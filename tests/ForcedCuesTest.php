<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Diff\CueDifference;
use SubtitleToolbox\Diff\SubtitleDiff;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Formatters\JsonFormatter;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\FakeOcrEngine;
use SubtitleToolbox\Parsers\JsonParser;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\VobSubParser;

require_once __DIR__ . "/Ocr/FakeOcrEngine.php";

class ForcedCuesTest extends TestCase
{
    private const SRT = "1\n00:00:01,000 --> 00:00:02,000\nHello\n\n2\n00:00:03,000 --> 00:00:04,000\nEXIT\n\n" .
                        "3\n00:00:05,000 --> 00:00:06,000\nBye\n";


    private function forcedFlags(Subtitle $subtitle): array
    {
        return array_values(array_map(fn (SubtitleCue $cue): bool => $cue->isForced(), $subtitle->getCues()));
    }


    private function srtWithForcedSecondCue(): Subtitle
    {
        $subtitle = Subtitle::parse(self::SRT, SubRipParser::class);
        $subtitle->getCues()[1]->setForced(true);

        return $subtitle;
    }


    public function testCueIsNotForcedByDefault(): void
    {
        $cue = new SubtitleCue(1, 2, "Hello");

        $this->assertFalse($cue->isForced());
        $this->assertTrue($cue->setForced(true)->isForced());
        $this->assertFalse($cue->setForced(false)->isForced());
    }


    public function testToArrayWritesForcedOnlyForForcedCues(): void
    {
        $cues = $this->srtWithForcedSecondCue()->toArray()["cues"];

        $this->assertSame(["start", "end", "lines", "identifier", "alignment", "formatData"], array_keys($cues[0]));
        $this->assertSame(["start", "end", "lines", "identifier", "alignment", "forced", "formatData"], array_keys($cues[1]));
        $this->assertTrue($cues[1]["forced"]);
        $this->assertSame(["start", "end", "lines", "identifier", "alignment"], array_keys($this->srtWithForcedSecondCue()->toArray(false)["cues"][0]));
        $this->assertTrue($this->srtWithForcedSecondCue()->toArray(false)["cues"][1]["forced"]);
    }


    public function testJsonKeepsTheFlagAndVersionOne(): void
    {
        $json = $this->srtWithForcedSecondCue()->format(JsonFormatter::class);

        $this->assertStringStartsWith('{"version":1,', $json);
        $this->assertSame(1, substr_count($json, '"forced":true'));
        $this->assertStringContainsString('"alignment":null,"forced":true,"formatData"', $json);
        $this->assertSame([false, true, false], $this->forcedFlags(Subtitle::parse($json, JsonParser::class)));
        $this->assertStringNotContainsString("forced", Subtitle::parse(self::SRT, SubRipParser::class)->format(JsonFormatter::class));
    }


    public function testFromArrayReadsTheFlag(): void
    {
        $subtitle = Subtitle::fromArray(["version" => 1, "cues" => [
            ["start" => 1, "end" => 2, "lines" => ["A"], "forced" => true],
            ["start" => 3, "end" => 4, "lines" => ["B"], "forced" => false],
            ["start" => 5, "end" => 6, "lines" => ["C"]],
        ]]);

        $this->assertSame([true, false, false], $this->forcedFlags($subtitle));
    }


    public function testFromArrayRejectsAFlagThatIsNotABoolean(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The field cues[0].forced must be a boolean.");

        Subtitle::fromArray(["version" => 1, "cues" => [["start" => 1, "end" => 2, "lines" => [], "forced" => "true"]]]);
    }


    public function testForcedOnlyReturnsACopyWithTheForcedCues(): void
    {
        $subtitle = $this->srtWithForcedSecondCue();
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, "en");
        $subtitle->addComment("before cue 1", 0);
        $subtitle->addComment("before cue 2", 1);
        $subtitle->addComment("at the end", 3);

        $forced = $subtitle->forcedOnly();

        $this->assertCount(1, $forced->getCues());
        $this->assertSame([3.0, 4.0, "EXIT", true], [$forced->getCues()[0]->getStart(), $forced->getCues()[0]->getEnd(),
                                                     $forced->getCues()[0]->getText(), $forced->getCues()[0]->isForced()]);
        $this->assertNotSame($subtitle->getCues()[1], $forced->getCues()[0]);
        $this->assertSame([["text" => "before cue 2", "beforeCueIndex" => 0]], $forced->getComments());
        $this->assertSame("en", $forced->getMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertCount(3, $subtitle->getCues());
        $this->assertCount(3, $subtitle->getComments());
        $this->assertSame([], (new Subtitle())->forcedOnly()->getCues());
    }


    public function testForcedOnlyKeepsTheLastCommentWhenTheLastCueIsForced(): void
    {
        $subtitle = Subtitle::parse(self::SRT, SubRipParser::class);
        $subtitle->getCues()[2]->setForced(true);
        $subtitle->addComment("at the end", 3);

        $this->assertSame([["text" => "at the end", "beforeCueIndex" => 1]], $subtitle->forcedOnly()->getComments());
    }


    public function testCueImageSetsTheFlag(): void
    {
        $image = new CueImage("png", 0, 0, 1, 1, 10, 10, true);

        $this->assertTrue($image->toCue(new SubtitleCue(1, 2))->isForced());
        $this->assertFalse((new CueImage("png", 0, 0, 1, 1, 10, 10))->toCue((new SubtitleCue(1, 2))->setForced(true))->isForced());
    }


    public function testPgsCuesCarryTheFlagThroughOcr(): void
    {
        $subtitle = (new PgsParser())->parse(file_get_contents(__DIR__ . "/files/pgs/shapes_1080p.sup"));
        $this->assertSame([false, false, true, true, false, false], $this->forcedFlags($subtitle));

        $subtitle->recognizeText(new FakeOcrEngine(["Platform 4"]), "eng");

        $this->assertSame([false, false, true, true, false, false], $this->forcedFlags($subtitle));
        $this->assertSame([8.0, 9.5, "Platform 4"], [$subtitle->getCues()[2]->getStart(), $subtitle->getCues()[2]->getEnd(),
                                                    $subtitle->getCues()[2]->getText()]);
        $this->assertCount(2, $subtitle->forcedOnly()->getCues());
        $this->assertStringStartsWith("1\n00:00:08,000 --> 00:00:09,500\n{\\an8}Platform 4\n\n",
                                      StringHelpers::removeUtf8Bom($subtitle->forcedOnly()->format(SubRipFormatter::class)));
    }


    public function testVobSubCuesCarryTheFlagThroughOcr(): void
    {
        $dir      = __DIR__ . "/files/vobsub/";
        $subtitle = (new VobSubParser(file_get_contents($dir . "two-tracks-pal.idx")))->parse(file_get_contents($dir . "two-tracks-pal.sub"));
        $this->assertSame([false, true, false, false, false], $this->forcedFlags($subtitle));

        $subtitle->recognizeText(new FakeOcrEngine(["Exit"]), "eng");

        $this->assertSame([false, true, false, false, false], $this->forcedFlags($subtitle));
    }


    public function testDiffReportsAChangedFlagAsATextChange(): void
    {
        $old = Subtitle::parse(self::SRT, SubRipParser::class);
        $new = $this->srtWithForcedSecondCue();

        $differences = SubtitleDiff::compare($old, $new);

        $this->assertCount(1, $differences);
        $this->assertSame([CueDifference::KIND_TEXT_CHANGED, 1, 1],
                          [$differences[0]->getKind(), $differences[0]->getOldIndex(), $differences[0]->getNewIndex()]);
        $this->assertSame("text changed: old cue 2, new cue 2\n" .
                          "- 00:00:03.000 --> 00:00:04.000\n  EXIT\n" .
                          "+ 00:00:03.000 --> 00:00:04.000 forced\n  EXIT\n",
                          SubtitleDiff::toText($differences));
        $this->assertTrue(SubtitleDiff::isEqual($new, $this->srtWithForcedSecondCue()));
    }
}
