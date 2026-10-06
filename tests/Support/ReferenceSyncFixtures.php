<?php

declare(strict_types=1);

namespace SubtitleToolbox\Tests\Support;

use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Fixtures for the ReferenceSync tests.
 */
trait ReferenceSyncFixtures
{
    private function load(string $name): Subtitle
    {
        return TestFiles::parse("sync/$name", Format::SubRip);
    }


    /**
     * Builds $cueCount cues with random gaps of 0.5 to 4 seconds and random durations of 1 to 5 seconds. The same
     * $seed gives the same cues.
     */
    private function makeRandomSubtitle(int $cueCount, int $seed): Subtitle
    {
        mt_srand($seed);
        $time = 1.0;
        $cues = [];
        for ($index = 0; $index < $cueCount; $index++) {
            $time     += mt_rand(500, 4000) / 1000;
            $duration  = mt_rand(1000, 5000) / 1000;
            $cues[]    = new SubtitleCue($time, $time + $duration, "text$index");
            $time     += $duration;
        }

        return (new Subtitle())->addCues($cues);
    }
}
