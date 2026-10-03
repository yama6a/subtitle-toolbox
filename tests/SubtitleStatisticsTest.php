<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Parsers\MicroDvdParser;

class SubtitleStatisticsTest extends TestCase
{
    private const FILES = __DIR__ . "/files";


    private function makeSubtitle(array $cues): Subtitle
    {
        $subtitle = new Subtitle();
        foreach ($cues as [$start, $end, $lines]) {
            $subtitle->addCue(new SubtitleCue($start, $end, $lines), false);
        }

        return $subtitle;
    }


    public function testOwnFile(): void
    {
        $subtitle   = Subtitle::fromString(file_get_contents(self::FILES . "/statistics/own_bakery.srt"), Format::SubRip);
        $statistics = SubtitleStatistics::of($subtitle);

        $this->assertSame(4, $statistics->getCueCount());
        $this->assertSame(26, $statistics->getWordCount());
        $this->assertSame(123, $statistics->getCharacterCount());
        $this->assertSame(9.0, $statistics->getTotalDisplayTime());
        $this->assertSame(11.0, $statistics->getSpan());
        $this->assertEqualsWithDelta(["min" => 38 / 3.5, "average" => (12 + 18.4 + 15 + 38 / 3.5) / 4, "max" => 18.4],
                                     $statistics->getCharactersPerSecond(), 0.0001);
        $this->assertEqualsWithDelta(["min" => 8 / 3.5 * 60, "average" => (150 + 240 + 180 + 8 / 3.5 * 60) / 4, "max" => 240.0],
                                     $statistics->getWordsPerMinute(), 0.0001);
        $this->assertSame(["min" => 15.0, "average" => 20.5, "max" => 26.0], $statistics->getCharactersPerLine());
        $this->assertEqualsWithDelta(["min" => -0.5, "average" => 2 / 3, "max" => 2.0], $statistics->getGap(), 0.0001);
        $this->assertSame(["the" => 4, "bread" => 3, "bakery" => 2, "at" => 2], $statistics->getMostUsedWords(4));
    }


    public function testToArrayEncodesAsJson(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "/statistics/own_bakery.srt"), Format::SubRip);
        $array    = SubtitleStatistics::of($subtitle)->toArray();

        $this->assertSame(["cueCount", "wordCount", "characterCount", "totalDisplayTime", "span", "charactersPerSecond",
                           "wordsPerMinute", "charactersPerLine", "gap", "mostUsedWords"], array_keys($array));
        $this->assertCount(10, $array["mostUsedWords"]);
        $this->assertSame(["min" => 15.0, "average" => 20.5, "max" => 26.0], $array["charactersPerLine"]);
        $this->assertJson(json_encode($array, JSON_THROW_ON_ERROR));
    }


    public function testSubtitleWithoutCuesGivesZeros(): void
    {
        $statistics = SubtitleStatistics::of(new Subtitle());
        $zero       = ["min" => 0.0, "average" => 0.0, "max" => 0.0];

        $this->assertSame([
            "cueCount"            => 0,
            "wordCount"           => 0,
            "characterCount"      => 0,
            "totalDisplayTime"    => 0.0,
            "span"                => 0.0,
            "charactersPerSecond" => $zero,
            "wordsPerMinute"      => $zero,
            "charactersPerLine"   => $zero,
            "gap"                 => $zero,
            "mostUsedWords"       => [],
        ], $statistics->toArray());
    }


    public function testImageCueAndCueWithoutTextCountOnlyAsCuesAndInTimes(): void
    {
        $subtitle = $this->makeSubtitle([[1, 3, "One two"], [9, 10, "<i> </i>"]]);
        $subtitle->addCue((new CueImage("png", 0, 0, 1, 1, 720, 576))->toCue(new SubtitleCue(4, 8)));
        $statistics = SubtitleStatistics::of($subtitle);

        $this->assertSame(3, $statistics->getCueCount());
        $this->assertSame(2, $statistics->getWordCount());
        $this->assertSame(7, $statistics->getCharacterCount());
        $this->assertSame(7.0, $statistics->getTotalDisplayTime());
        $this->assertSame(9.0, $statistics->getSpan());
        $this->assertSame(["min" => 3.5, "average" => 3.5, "max" => 3.5], $statistics->getCharactersPerSecond());
        $this->assertSame(["min" => 60.0, "average" => 60.0, "max" => 60.0], $statistics->getWordsPerMinute());
        $this->assertSame(["min" => 7.0, "average" => 7.0, "max" => 7.0], $statistics->getCharactersPerLine());
        $this->assertSame(["min" => 1.0, "average" => 1.0, "max" => 1.0], $statistics->getGap());
    }


    public function testCueWithoutDurationHasNoReadingSpeed(): void
    {
        $statistics = SubtitleStatistics::of($this->makeSubtitle([[1, 1, "Rain"], [2, 4, "Sun today"]]));

        $this->assertSame(13, $statistics->getCharacterCount());
        $this->assertSame(["min" => 4.5, "average" => 4.5, "max" => 4.5], $statistics->getCharactersPerSecond());
        $this->assertSame(["min" => 60.0, "average" => 60.0, "max" => 60.0], $statistics->getWordsPerMinute());
    }


    public function testCountsCharactersLikeValidation(): void
    {
        $statistics = SubtitleStatistics::of($this->makeSubtitle([
            [0, 2, ["<v Anna> Gr\u{fc}\u{df}e &amp; <00:00:01.000>Tee </v>", "\u{4f60}\u{597d}"]],
        ]));

        $this->assertSame(13, $statistics->getCharacterCount());
        $this->assertSame(4, $statistics->getWordCount());
        $this->assertSame(["min" => 2.0, "average" => 6.5, "max" => 11.0], $statistics->getCharactersPerLine());
    }


    public function testMostUsedWordsIgnoreCaseAndOuterPunctuation(): void
    {
        $statistics = SubtitleStatistics::of($this->makeSubtitle([
            [0, 2, ["- \u{c4}pfel? \"Apples!\"", "\u{e4}pfel, don't... apples"]],
            [3, 4, "Don't stop -- 12:30."],
        ]));

        $this->assertSame(10, $statistics->getWordCount());
        $this->assertSame(["\u{e4}pfel" => 2, "apples" => 2, "don't" => 2, "stop" => 1, "12:30" => 1],
                          $statistics->getMostUsedWords(10));
        $this->assertSame(["\u{e4}pfel" => 2], $statistics->getMostUsedWords(1));
        $this->assertSame([], $statistics->getMostUsedWords(0));
    }


    public function testCountsInvalidUtf8ByBytes(): void
    {
        $content    = "{1}{1}25.000\n{25}{75}Caf\xe9 au lait\n";
        $statistics = SubtitleStatistics::of((new MicroDvdParser())->parse($content));

        $this->assertSame(1, $statistics->getCueCount());
        $this->assertSame(3, $statistics->getWordCount());
        $this->assertSame(12, $statistics->getCharacterCount());
        $this->assertSame(["caf\xe9" => 1, "au" => 1, "lait" => 1], $statistics->getMostUsedWords(10));
    }
}
