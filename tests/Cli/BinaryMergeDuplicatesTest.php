<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Tests\Support\BinaryTestCase;

class BinaryMergeDuplicatesTest extends BinaryTestCase
{
    public function testMergeDuplicatesJoinsExactAndOverlappingDuplicates(): void
    {
        $files = __DIR__ . "/../files/editing/";
        copy($files . "own_duplicate_cues.srt", "$this->dir/duplicates.srt");

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "duplicates.srt", "--to", "srt", "-o", "out.srt", "--no-bom", "--structure-merge-duplicates"]);

        $this->assertSame([0, "duplicates.srt -> out.srt\n", ""], [$code, $stdout, $stderr]);
        $this->assertSame(file_get_contents($files . "own_duplicate_cues_deduplicated.srt"), $this->file("out.srt"));
    }
}
