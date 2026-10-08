<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class SubtitleCloneTest extends TestCase
{
    private const FILE = __DIR__ . "/files/srt/real/own_styled.srt";


    public function testShiftOnACloneKeepsTheOriginalCues(): void
    {
        $original = Subtitle::fromString(file_get_contents(self::FILE), Format::SubRip);
        $before   = $original->toString(Format::SubRip);

        $copy = clone $original;
        $copy->shift(2);

        $this->assertSame($before, $original->toString(Format::SubRip));
        $this->assertSame(17.985, $original->getCues()[0]->getStart());
        $this->assertSame(19.985, $copy->getCues()[0]->getStart());
    }


    public function testCueEditsOnACloneKeepTheOriginalCues(): void
    {
        $original = Subtitle::fromString(file_get_contents(self::FILE), Format::SubRip);
        $before   = $original->toString(Format::SubRip);

        $copy = clone $original;
        $copy->getCues()[0]->setLines(["changed"])->setFormatData("srt", ["changed" => true]);
        $copy->stripFormatting();

        $this->assertSame($before, $original->toString(Format::SubRip));
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


    public function testEmptyCopyKeepsMetadataAndFormatWithoutCuesAndComments(): void
    {
        $original = Subtitle::fromString(file_get_contents(self::FILE), Format::SubRip)
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "en")
            ->addComment("Before the first cue", 0);

        $copy = $original->emptyCopy();

        $this->assertSame([], $copy->getCues());
        $this->assertSame([], $copy->getComments());
        $this->assertSame("en", $copy->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame(Format::SubRip, $copy->getFormat());
        $this->assertNotSame([], $original->getCues());
    }
}
