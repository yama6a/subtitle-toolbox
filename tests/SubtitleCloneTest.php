<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Parsers\SubRipParser;

class SubtitleCloneTest extends TestCase
{
    private const FILE = __DIR__ . "/files/srt/real/own_styled.srt";


    public function testShiftOnACloneKeepsTheOriginalCues(): void
    {
        $original = Subtitle::parse(file_get_contents(self::FILE), SubRipParser::class);
        $before   = $original->format(SubRipFormatter::class);

        $copy = clone $original;
        $copy->shift(2);

        $this->assertSame($before, $original->format(SubRipFormatter::class));
        $this->assertSame(17.985, $original->getCues()[0]->getStart());
        $this->assertSame(19.985, $copy->getCues()[0]->getStart());
    }


    public function testCueEditsOnACloneKeepTheOriginalCues(): void
    {
        $original = Subtitle::parse(file_get_contents(self::FILE), SubRipParser::class);
        $before   = $original->format(SubRipFormatter::class);

        $copy = clone $original;
        $copy->getCues()[0]->setLines(["changed"])->setFormatData("srt", ["changed" => true]);
        $copy->stripFormatting();

        $this->assertSame($before, $original->format(SubRipFormatter::class));
        $this->assertCount(count($original->getCues()), $copy->getCues());
        foreach ($copy->getCues() as $cueIndex => $cue) {
            $this->assertNotSame($original->getCues()[$cueIndex], $cue);
        }
    }


    public function testCloneKeepsCuesMetadataCommentsAndFormatData(): void
    {
        $original = new Subtitle();
        $original->addCue(new SubtitleCue(1, 2, "first"))->addCue(new SubtitleCue(3, 4, "second"));
        $original->setMetadata(Subtitle::METADATA_TITLE, "Clone test");
        $original->addComment("before second", 1);
        $original->setFormatData("srt", ["key" => "value"]);

        $copy = clone $original;

        $this->assertSame($original->toArray(), $copy->toArray());
    }
}
