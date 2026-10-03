<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Diff\SubtitleDiff;
use SubtitleToolbox\Diff\SubtitleDiffOptions;
use SubtitleToolbox\Formatters\FormatWriteOptions;
use SubtitleToolbox\Formatters\Options\IttOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdOptions;
use SubtitleToolbox\Validation\ValidationResult;
use SubtitleToolbox\Validation\ValidationRules;

class QuotesInTagsTest extends TestCase
{
    private function loadAss(): Subtitle
    {
        return Subtitle::fromString(file_get_contents(__DIR__ . "/files/quotes-in-tags/own_names_with_quotes.ass"), Format::Ass);
    }


    private function makeSubtitle(string ...$texts): Subtitle
    {
        $subtitle = new Subtitle();
        foreach ($texts as $index => $text) {
            $subtitle->addCue(new SubtitleCue($index * 2 + 1, $index * 2 + 2, $text));
        }

        return $subtitle;
    }


    public function testAssNamesWithQuotesConvertToSubRip(): void
    {
        $this->assertSame(
            "\u{FEFF}1\n00:00:01,000 --> 00:00:03,000\nHi.\n\n"
            . "2\n00:00:03,500 --> 00:00:06,000\nWe're out of rye bread today.\n\n"
            . "3\n00:00:06,500 --> 00:00:09,000\nThen I'll take the \"seeded\" loaf.\n\n"
            . "4\n00:00:09,500 --> 00:00:12,000\nTwo <i>warm</i> rolls.\n\n"
            . "5\n00:00:12,500 --> 00:00:15,000\n<font color=\"#ff0000\">Red</font> jam, please.\n",
            $this->loadAss()->toString(Format::SubRip)
        );
    }


    public function testAssNamesWithQuotesConvertToWebVtt(): void
    {
        $this->assertSame(
            "\u{FEFF}WEBVTT\n\n1\n00:00:01.000 --> 00:00:03.000\n<v O'Neil>Hi.\n\n"
            . "2\n00:00:03.500 --> 00:00:06.000\n<v O'Neil>We're out of rye bread today.\n\n"
            . "3\n00:00:06.500 --> 00:00:09.000\n<v Sam \"Ace\" Reed>Then I'll take the \"seeded\" loaf.\n\n"
            . "4\n00:00:09.500 --> 00:00:12.000\n<v Mo \"Baker>Two <i>warm</i> rolls.\n\n"
            . "5\n00:00:12.500 --> 00:00:15.000\n<v D'Arcy>Red jam, please.\n",
            $this->loadAss()->toString(Format::WebVtt)
        );
    }


    public function testAssNamesWithQuotesConvertToPlainText(): void
    {
        $this->assertSame(
            "Hi. We're out of rye bread today. Then I'll take the \"seeded\" loaf. Two warm rolls. Red jam, please.\n",
            $this->loadAss()->toString(Format::PlainText)
        );
    }


    public static function formatterProvider(): array
    {
        return [
            "itt"       => [Format::Itt, new IttOptions(frameRate: 25)],
            "lrc"       => [Format::Lyrics, null],
            "microdvd"  => [Format::MicroDvd, new MicroDvdOptions(frameRate: 25)],
            "mpsub"     => [Format::MpSub, null],
            "sami"      => [Format::Sami, null],
            "sbv"       => [Format::Sbv, null],
            "srt"       => [Format::SubRip, null],
            "subviewer" => [Format::SubViewer, null],
            "txt"       => [Format::PlainText, null],
            "vtt"       => [Format::WebVtt, null],
        ];
    }


    #[DataProvider("formatterProvider")]
    public function testFormatterKeepsTextAfterQuotesInTags(Format $format, ?FormatWriteOptions $options): void
    {
        $subtitle = $this->makeSubtitle("<v O'Neil>We're out of rye.", '<v Mo "Baker>Two rolls.');

        foreach ([false, true] as $stripTags) {
            $output = $subtitle->toString($format, new WriteOptions(stripTags: $stripTags, format: $options));

            $this->assertStringContainsString("We're out of rye.", $output);
            $this->assertStringContainsString("Two rolls.", $output);
        }
    }


    public function testStatisticsCountWordsAfterQuotesInTags(): void
    {
        $this->assertSame(5, SubtitleStatistics::of($this->makeSubtitle("<v O'Neil>We're out of rye.", '<v Mo "Baker>Hi.'))->getWordCount());
    }


    public function testStripFormattingKeepsTextAfterQuotesInTags(): void
    {
        $subtitle = $this->makeSubtitle("<v O'Neil><i>We're</i> out.")->stripFormatting(["i"]);

        $this->assertSame([["<i>We're</i> out."]], array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $subtitle->getCues()));
    }


    public function testDiffIgnoringFormattingSeesTextAfterQuotesInTags(): void
    {
        $differences = SubtitleDiff::compare($this->makeSubtitle("<v O'Neil>We're out."), $this->makeSubtitle("<v O'Neil>We're open."),
                                             new SubtitleDiffOptions(ignoreFormatting: true));

        $this->assertCount(1, $differences);
    }


    public function testMergeShortCuesSeesSentenceEndAfterQuotesInTags(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(0, 0.5, "<v O'Neil>Wait."))
            ->addCue(new SubtitleCue(0.5, 1, "<v O'Neil>Now."))
            ->mergeShortCues(new MergeShortCuesOptions(keepSentenceEnds: true));

        $this->assertCount(2, $subtitle->getCues());
    }


    public function testValidationSeesTextAfterQuotesInTags(): void
    {
        $results = $this->makeSubtitle("<v O'Neil>WE'RE OUT.")->validate(new ValidationRules(noEmptyCues: true, noAllCapsLines: true));

        $this->assertSame([ValidationResult::RULE_NO_ALL_CAPS_LINES], array_map(fn (ValidationResult $result): string => $result->getRule(), $results));
    }
}
