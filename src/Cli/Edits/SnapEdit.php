<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Timing\ShotChanges;
use SubtitleToolbox\Timing\ShotChangeTiming;

/**
 * @internal
 */
final class SnapEdit extends Edit
{
    private function __construct(private readonly ShotChangeOptions $options)
    {
    }


    public static function group(): string
    {
        return "snap";
    }


    public static function summary(): string
    {
        return "Time cues to shot changes and close small gaps.";
    }


    public static function options(): array
    {
        return [
            Option::value("snap-shot-changes", "FILE", "Time cues to these shot changes: one time per line in seconds or hh:mm:ss.mmm, or the log of the FFmpeg showinfo filter."),
            Option::value("video-fps", "RATE", "Frame rate of the video, for the shot changes and the --snap- options. Required with them."),
            Option::value("snap-window-frames", "FRAMES", "Largest move to a shot change, and largest gap that closes. Default: half a second."),
            Option::value("snap-min-gap-frames", "FRAMES", "Gap between a cue and the next cue or shot change. Default: 2."),
            Option::value("snap-min-duration-frames", "FRAMES", "No move makes a cue shorter than this. Default: 20."),
            Option::flag("no-snap-chain", "Keep small gaps between cues."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        $videoFps = $arguments->rate("video-fps");
        $snaps    = array_filter(["snap-shot-changes", "snap-window-frames", "snap-min-gap-frames", "snap-min-duration-frames", "no-snap-chain"], $arguments->has(...));
        if ($snaps === []) {
            if ($arguments->has("video-fps")) {
                Command::fail("Pass --snap-shot-changes FILE with --video-fps.");
            }

            return null;
        }
        if ($videoFps === null) {
            Command::fail("Pass --video-fps RATE or --fps RATE with --" . reset($snaps) . ".");
        }
        $path = $arguments->value("snap-shot-changes");
        if ($path === null && $arguments->has("no-snap-chain")) {
            Command::fail("Pass --snap-shot-changes FILE. With --no-snap-chain and no shot changes, snapping changes nothing.");
        }

        return new self(new ShotChangeOptions(...Command::given([
            "frameRate"         => $videoFps,
            "shotChanges"       => $path === null ? null : self::loadShotChanges($path),
            "snapWindowFrames"  => $arguments->int("snap-window-frames", 0),
            "minGapFrames"      => $arguments->int("snap-min-gap-frames", 0),
            "chain"             => !$arguments->has("no-snap-chain"),
            "minDurationFrames" => $arguments->int("snap-min-duration-frames", 0),
        ])));
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        ShotChangeTiming::apply($subtitle, $this->options);

        return $subtitle;
    }


    /**
     * @return list<float>
     */
    private static function loadShotChanges(string $path): array
    {
        $content = Command::readSideFile($path);

        try {
            return str_contains($content, "pts_time:") ? ShotChanges::fromFfmpegLog($content) : ShotChanges::fromText($content);
        } catch (ParsingException $exception) {
            return Command::failSideFile($path, $exception->getMessage());
        }
    }
}
