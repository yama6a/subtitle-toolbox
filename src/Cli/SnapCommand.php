<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Timing\ShotChanges;
use SubtitleToolbox\Timing\ShotChangeTiming;

class SnapCommand extends WriteCommand
{
    private ?ShotChangeOptions $timing = null;


    public function name(): string
    {
        return "snap";
    }


    public function summary(): string
    {
        return "Times cues to shot changes and closes small gaps, as the Netflix timing rules require.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --video-fps RATE [--shot-changes FILE] [options]"];
    }


    protected function details(): string
    {
        return "A start up to --snap-window frames after a shot change moves to it. An end up to --snap-window frames\n" .
               "before a shot change ends --min-gap-frames before it. A gap shorter than --snap-window frames closes to\n" .
               "--min-gap-frames, unless a shot change is in it. All times land on frames. Without --shot-changes, snap\n" .
               "only closes small gaps. Without --output, --output-dir or --in-place, the result of one input file goes\n" .
               "to standard output.";
    }


    protected function fpsDescription(): string
    {
        return "Sets --input-fps, --output-fps and --video-fps. Each of them overrides it.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("video-fps", "RATE", "Frame rate of the video, for the frames of the shot changes and of the other options. Required."),
            Option::value("shot-changes", "FILE", "Shot change times, one per line in seconds or hh:mm:ss.mmm, or the log of the FFmpeg showinfo filter."),
            Option::value("snap-window", "FRAMES", "Largest move to a shot change, and largest gap that closes. Default: half a second."),
            Option::value("min-gap-frames", "FRAMES", "Gap between a cue and the next cue or shot change. Default: 2."),
            Option::value("min-duration-frames", "FRAMES", "No move makes a cue shorter than this. Default: 20."),
            Option::flag("no-chain", "Keep small gaps between cues."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $videoFps = self::rate($arguments, "video-fps") ?? self::fail("Pass --video-fps RATE.");
        $path = $arguments->value("shot-changes");
        if ($path === null && $arguments->has("no-chain")) {
            self::fail("Pass --shot-changes FILE. With --no-chain and no shot changes, snap changes nothing.");
        }

        $shotChanges = $path === null ? [] : self::loadShotChanges($path);
        try {
            $this->timing = new ShotChangeOptions(
                frameRate: $videoFps,
                shotChanges: $shotChanges,
                snapWindow: self::frames($arguments, "snap-window"),
                minGapFrames: self::frames($arguments, "min-gap-frames") ?? 2,
                chain: !$arguments->has("no-chain"),
                minDuration: self::frames($arguments, "min-duration-frames") ?? 20,
            );
        } catch (InvalidArgumentException $exception) {
            self::fail($exception->getMessage());
        }
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments): void
    {
        ShotChangeTiming::apply($subtitle, $this->timing);
    }


    private static function frames(Arguments $arguments, string $name): ?int
    {
        $value = $arguments->value($name);
        if ($value !== null && !ctype_digit($value)) {
            self::fail("The option --$name needs a whole number of frames, got \"$value\".");
        }

        return $value === null ? null : (int)$value;
    }


    /**
     * @return list<float>
     */
    private static function loadShotChanges(string $path): array
    {
        $content = is_file($path) ? @file_get_contents($path) : false;
        if ($content === false) {
            self::fail("Cannot read the shot change file $path.");
        }

        try {
            return str_contains($content, "pts_time:") ? ShotChanges::fromFfmpegLog($content) : ShotChanges::fromText($content);
        } catch (ParsingException $exception) {
            return self::fail("$path: " . $exception->getMessage());
        }
    }
}
