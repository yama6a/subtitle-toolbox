<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Hls\HlsSegmentOptions;
use SubtitleToolbox\Hls\HlsWebVttSegmenter;
use SubtitleToolbox\Subtitle;

class HlsCommand extends FileCommand
{
    private ?HlsSegmentOptions $segmentOptions = null;

    private string $directory = "";

    private string $playlist = "";


    public function name(): string
    {
        return "hls";
    }


    public function summary(): string
    {
        return "Cuts a subtitle into WebVTT segments and writes an HLS playlist for them.";
    }


    protected function usageLines(): array
    {
        return ["<input> --output-dir DIR [--segment SECONDS] [options]"];
    }


    protected function details(): string
    {
        return "Each segment starts with an X-TIMESTAMP-MAP header and holds every cue that overlaps it, with its full\n" .
               "times. The playlist is a VOD media playlist.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("output-dir", "DIR", "Directory for the segments and the playlist. Creates it when it is missing."),
            Option::value("segment", "SECONDS", "Duration of a segment. Default: 6."),
            Option::value("playlist", "NAME", "File name of the playlist. Default: subs.m3u8."),
            Option::value("pattern", "PATTERN", "File name of a segment, with %d for its number from 0. Default: sub%d.vtt."),
            Option::value("mpegts", "TICKS", "90 kHz MPEG-2 timestamp at which subtitle time 0 plays. Default: 900000."),
            Option::value("local", "SECONDS", "WebVTT cue time that maps to --mpegts. Default: 0."),
            Option::value("media-duration", "SECONDS", "Duration of the video, so the playlist covers it all. Default: the end of the last cue."),
            Option::flag("force", "Overwrite files that exist."),
        ];
    }


    protected function inputOptions(): array
    {
        return array_values(array_filter(parent::inputOptions(), fn (Option $option): bool => $option->name !== "keep-going"));
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->directory = $arguments->value("output-dir") ?? self::fail("Pass --output-dir DIR.");
        $this->playlist  = $arguments->value("playlist") ?? "subs.m3u8";

        $mpegts = $arguments->value("mpegts") ?? (string)HlsSegmentOptions::DEFAULT_MPEGTS;
        if (!ctype_digit($mpegts)) {
            self::fail("The option --mpegts needs a whole number, got \"$mpegts\".");
        }
        if (($arguments->float("local") ?? 0) < 0) {
            self::fail("The option --local must not be negative.");
        }

        try {
            $this->segmentOptions = new HlsSegmentOptions(
                segmentDuration: $arguments->positiveFloat("segment") ?? 6,
                mpegts: (int)$mpegts,
                local: $arguments->float("local") ?? 0,
                fileNamePattern: $arguments->value("pattern") ?? "sub%d.vtt",
                mediaDuration: $arguments->positiveFloat("media-duration"),
            );
        } catch (InvalidArgumentException $exception) {
            self::fail($exception->getMessage());
        }
    }


    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        if (count($inputs) > 1) {
            self::fail("The hls command takes one input file, got " . count($inputs) . ".");
        }
    }


    protected function process(string $input, Subtitle $subtitle, string $format, Arguments $arguments, Console $console): void
    {
        $result    = HlsWebVttSegmenter::segment($subtitle, $this->segmentOptions);
        $directory = rtrim($this->directory, "/\\");
        $files     = [...$result->getSegments(), $this->playlist => $result->getPlaylist()];

        if (!$arguments->has("force")) {
            foreach (array_keys($files) as $name) {
                if (file_exists("$directory/$name")) {
                    self::fail("$directory/$name exists. Pass --force to overwrite it.");
                }
            }
        }
        if (!is_dir($directory) && !@mkdir($directory, 0777, true)) {
            self::fail("Cannot create the directory $directory.");
        }
        foreach ($files as $name => $content) {
            if (@file_put_contents("$directory/$name", $content) === false) {
                self::fail("Cannot write $directory/$name.");
            }
        }

        $console->out(self::label($input) . " -> $directory/$this->playlist, " . count($result->getSegments()) . " segments\n");
    }
}
