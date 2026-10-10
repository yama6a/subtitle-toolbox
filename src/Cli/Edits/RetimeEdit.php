<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SyncPoint;

/**
 * @internal
 */
final class RetimeEdit extends Edit
{
    /**
     * @param list<array{string, float|int|string, float}> $sync the text of each --sync point, its old time or cue name, and its new time
     */
    private function __construct(
        private readonly ?float $shift,
        private readonly ?float $shiftAfter,
        private readonly ?float $shiftBefore,
        private readonly ?float $scale,
        private readonly ?float $fromFps,
        private readonly ?float $toFps,
        private readonly array $sync,
    ) {
    }


    public static function group(): string
    {
        return "retime";
    }


    public static function summary(): string
    {
        return "Shift and scale the times, or change the frame rate.";
    }


    public static function options(): array
    {
        return [
            Option::value("shift", "SECONDS", "Time to add to every time, for example 2.5 or 00:00:02.500, or -2.5 to show the cues earlier."),
            Option::value("shift-after", "SECONDS", "Shift only the cues that start at this time or later."),
            Option::value("shift-before", "SECONDS", "Shift only the cues that start before this time."),
            Option::value("scale", "FACTOR", "Multiply every time by this factor. Must be greater than 0."),
            Option::repeatable("sync", "OLD=NEW", "Move the time OLD to NEW and correct the times between. OLD is a time, first, last or #N, the start of cue N. Repeatable."),
            Option::value("from-fps", "RATE", "Frame rate of the video that the subtitle fits now. Needs --to-fps."),
            Option::value("to-fps", "RATE", "Frame rate of the video that the subtitle must fit. Needs --from-fps."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "shift", ["shift-after", "shift-before"]);
        if ($arguments->has("sync") && ($arguments->has("shift") || $arguments->has("scale"))) {
            Command::fail("Pass --sync without --shift and --scale.");
        }
        $edit = new self(
            $arguments->seconds("shift"),
            $arguments->seconds("shift-after"),
            $arguments->seconds("shift-before"),
            $arguments->positiveFloat("scale"),
            $arguments->positiveFloat("from-fps"),
            $arguments->positiveFloat("to-fps"),
            self::syncPoints($arguments->values("sync")),
        );
        if ($edit->shiftAfter !== null && $edit->shiftBefore !== null && !($edit->shiftBefore > $edit->shiftAfter)) {
            Command::fail("The option --shift-before must be after --shift-after.");
        }
        if (($edit->fromFps === null) !== ($edit->toFps === null)) {
            Command::fail("Pass --from-fps and --to-fps together.");
        }

        return $edit->shift === null && $edit->scale === null && $edit->fromFps === null && $edit->sync === [] ? null : $edit;
    }


    /**
     * @param list<string> $values
     * @return list<array{string, float|int|string, float}>
     */
    private static function syncPoints(array $values): array
    {
        $points = [];
        foreach ($values as $value) {
            $parts = explode("=", $value, 2);
            if (count($parts) !== 2) {
                Command::fail("The option --sync needs OLD=NEW, got \"$value\".");
            }
            [$old, $new] = $parts;
            $points[]    = [$value, match (true) {
                $old === "first", $old === "last" => $old,
                str_starts_with($old, "#")        => self::cueNumber($old, $value),
                default                           => Arguments::time("sync", $old),
            }, Arguments::time("sync", $new)];
        }
        self::checkOrder($points, fn (array $point): ?float => is_float($point[1]) ? $point[1] : null);

        return $points;
    }


    private static function cueNumber(string $old, string $value): int
    {
        // 9 digits keep the number far below PHP_INT_MAX.
        if (preg_match('/^#0*([1-9][0-9]{0,8})$/', $old, $matches) !== 1) {
            Command::fail("The option --sync needs a cue number from 1 after #, got \"$value\".");
        }

        return (int)$matches[1];
    }


    /**
     * Fails when 2 points whose old times $oldTime knows do not increase in both times.
     *
     * @param list<array{string, float|int|string, float}> $points
     * @param \Closure(array{string, float|int|string, float}): ?float $oldTime
     */
    private static function checkOrder(array $points, \Closure $oldTime): void
    {
        $known = array_values(array_filter($points, fn (array $point): bool => $oldTime($point) !== null));
        for ($index = 1; $index < count($known); $index++) {
            [$previous, $point] = [$known[$index - 1], $known[$index]];
            if (!($oldTime($point) > $oldTime($previous) && $point[2] > $previous[2])) {
                Command::fail("The --sync points must increase in both times, got \"$previous[0]\" before \"$point[0]\".");
            }
        }
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        if ($this->sync !== []) {
            $subtitle->syncByPoints($this->resolveSync($subtitle));
        }
        if ($this->shift !== null) {
            $subtitle->shift($this->shift, $this->shiftAfter, $this->shiftBefore);
        }
        if ($this->scale !== null) {
            $subtitle->scale($this->scale);
        }
        if ($this->fromFps !== null && $this->toFps !== null) {
            $subtitle->convertFrameRate($this->fromFps, $this->toFps);
        }

        return $subtitle;
    }


    /**
     * @return list<SyncPoint>
     */
    private function resolveSync(Subtitle $subtitle): array
    {
        $starts = array_map(fn ($cue): float => $cue->getStart(), $subtitle->getCues());
        if ($starts === []) {
            Command::fail("The --sync points need a subtitle with cues.");
        }
        $resolved = [];
        foreach ($this->sync as [$text, $old, $new]) {
            $resolved[] = [$text, match (true) {
                $old === "first" => min($starts),
                $old === "last"  => max($starts),
                is_int($old)     => $starts[$old - 1] ?? Command::fail("The --sync point \"$text\" names cue $old, but the subtitle has " . count($starts) . " cues."),
                default          => (float)$old,
            }, $new];
        }
        // Inside apply(), the command reports the failure for this file only, so the exit code is 3.
        self::checkOrder($resolved, fn (array $point): float => (float)$point[1]);

        return array_map(fn (array $point): SyncPoint => new SyncPoint((float)$point[1], $point[2]), $resolved);
    }
}
