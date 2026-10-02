<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Validation\ValidationResult;
use SubtitleToolbox\Validation\ValidationRules;

class ValidationTest extends TestCase
{
    private const FILES = __DIR__ . "/files";


    private function parseFile(string $path, string $parserClass): Subtitle
    {
        return Subtitle::parse(file_get_contents(self::FILES . "/" . $path), $parserClass);
    }


    private function makeSubtitle(array $cues): Subtitle
    {
        $subtitle = new Subtitle();
        foreach ($cues as [$start, $end, $lines]) {
            $subtitle->addCue(new SubtitleCue($start, $end, $lines), false);
        }

        return $subtitle;
    }


    /**
     * @param list<ValidationResult> $results
     */
    private function toArrays(array $results): array
    {
        return array_map(fn (ValidationResult $result): array => [
            $result->getCueIndex(),
            $result->getRule(),
            $result->getValue(),
            $result->getLimit(),
        ], $results);
    }


    public function testNoRulesGiveNoResults(): void
    {
        $subtitle = $this->parseFile("validation/own_netflix_checks.srt", SubRipParser::class);

        $this->assertSame([], $subtitle->validate(new ValidationRules()));
    }


    public function testNetflixEnglishPresetOnOwnFile(): void
    {
        $subtitle = $this->parseFile("validation/own_netflix_checks.srt", SubRipParser::class);

        $this->assertCount(7, $subtitle->getCues());
        $this->assertSame([
            [1, ValidationResult::RULE_MAX_CHARACTERS_PER_LINE, 45, 42],
            [1, ValidationResult::RULE_MAX_CHARACTERS_PER_SECOND, 112.5, 20.0],
            [1, ValidationResult::RULE_MIN_DURATION, 0.4, 5 / 6],
            [1, ValidationResult::RULE_MIN_GAP, 0.04, 2 / 24],
            [2, ValidationResult::RULE_MAX_CHARACTERS_PER_LINE, 49, 42],
            [2, ValidationResult::RULE_MAX_LINES_PER_CUE, 3, 2],
            [2, ValidationResult::RULE_MAX_DURATION, 7.5, 7.0],
            [3, ValidationResult::RULE_OVERLAP, 0.5, null],
        ], $this->toArrays($subtitle->validate(ValidationRules::netflixEnglish(24))));
    }


    public function testNetflixEnglishPresetOnRealSubRipFile(): void
    {
        $subtitle = $this->parseFile("srt/real/language_subtitles_dots_tester.srt", SubRipParser::class);

        $this->assertSame([
            [0, ValidationResult::RULE_MAX_CHARACTERS_PER_LINE, 62, 42],
            [0, ValidationResult::RULE_MAX_CHARACTERS_PER_SECOND, 98 / 1.999, 20.0],
            [1, ValidationResult::RULE_MIN_GAP, 0.001, 2 / 24],
            [2, ValidationResult::RULE_OVERLAP, 1.999, null],
        ], $this->toArrays($subtitle->validate(ValidationRules::netflixEnglish(24))));
    }


    public function testNetflixEnglishPresetOnRealWebVttFile(): void
    {
        $subtitle = $this->parseFile("vtt/real/w3c_comments.vtt", WebVttParser::class);

        $this->assertSame([], $subtitle->validate(ValidationRules::netflixEnglish(24)));
        $this->assertSame(
            [[1, ValidationResult::RULE_MAX_CHARACTERS_PER_LINE, 33, 30]],
            $this->toArrays($subtitle->validate(new ValidationRules(maxCharactersPerLine: 30)))
        );
    }


    public function testNetflixEnglishPresetValues(): void
    {
        $rules = ValidationRules::netflixEnglish(25);

        $this->assertSame(20.0, $rules->maxCharactersPerSecond);
        $this->assertSame(42, $rules->maxCharactersPerLine);
        $this->assertSame(2, $rules->maxLinesPerCue);
        $this->assertSame(5 / 6, $rules->minDuration);
        $this->assertSame(7.0, $rules->maxDuration);
        $this->assertSame(0.08, $rules->minGap);
        $this->assertTrue($rules->noOverlap);
        $this->assertFalse($rules->noEmptyCues);
    }


    public function testEmptyCueRule(): void
    {
        $subtitle = $this->parseFile("validation/own_netflix_checks.srt", SubRipParser::class);

        $this->assertSame(
            [[6, ValidationResult::RULE_EMPTY_CUE, 0, null]],
            $this->toArrays($subtitle->validate(new ValidationRules(noEmptyCues: true)))
        );
    }


    public function testCountsCharactersWithoutMarkupAndWithMultibyteLetters(): void
    {
        $subtitle = $this->makeSubtitle([
            [0, 10, ["<b>Größe: zwölf Äpfel, dreißig Birnen, sechs.</b>", "<i>Fish &amp; chips</i>"]],
        ]);

        $this->assertSame([], $subtitle->validate(new ValidationRules(maxCharactersPerLine: 42)));
        $this->assertSame(
            [[0, ValidationResult::RULE_MAX_CHARACTERS_PER_LINE, 42, 41]],
            $this->toArrays($subtitle->validate(new ValidationRules(maxCharactersPerLine: 41)))
        );
        $this->assertSame(
            [[0, ValidationResult::RULE_MAX_CHARACTERS_PER_SECOND, 5.4, 5.0]],
            $this->toArrays($subtitle->validate(new ValidationRules(maxCharactersPerSecond: 5)))
        );
    }


    public function testCountsBytesOfInvalidUtf8(): void
    {
        $subtitle = $this->makeSubtitle([[0, 1, "a\xff\xfe"]]);

        $this->assertSame(
            [[0, ValidationResult::RULE_MAX_CHARACTERS_PER_LINE, 3, 2]],
            $this->toArrays($subtitle->validate(new ValidationRules(maxCharactersPerLine: 2)))
        );
    }


    public function testLinesWithOnlyMarkupDoNotCount(): void
    {
        $subtitle = $this->makeSubtitle([[0, 1, ["One", "<i> </i>", "Two"]]]);

        $this->assertSame([], $subtitle->validate(new ValidationRules(maxLinesPerCue: 2)));
    }


    public function testZeroDurationGivesInfiniteReadingSpeed(): void
    {
        $subtitle = $this->makeSubtitle([[5, 5, "Hi"], [6, 6, ""]]);

        $this->assertSame(
            [[0, ValidationResult::RULE_MAX_CHARACTERS_PER_SECOND, INF, 20.0]],
            $this->toArrays($subtitle->validate(new ValidationRules(maxCharactersPerSecond: 20)))
        );
    }


    public function testLimitsMatchCueTimesInMilliseconds(): void
    {
        $subtitle = $this->makeSubtitle([[0, 0.833, "a"], [0.916, 1.749, "b"], [1.831, 2.664, "c"]]);

        $this->assertSame(
            [[2, ValidationResult::RULE_MIN_GAP, 0.082, 2 / 24]],
            $this->toArrays($subtitle->validate(new ValidationRules(minDuration: 5 / 6, minGap: 2 / 24)))
        );
    }


    public function testOverlapUsesLatestEndOfAllEarlierCues(): void
    {
        $subtitle = $this->makeSubtitle([[0, 10, "long"], [2, 3, "short"], [5, 6, "inside"], [10, 11, "after"]]);

        $this->assertSame([
            [1, ValidationResult::RULE_OVERLAP, 8.0, null],
            [2, ValidationResult::RULE_OVERLAP, 5.0, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(minGap: 0, noOverlap: true))));
    }


    public function testGapRuleSkipsOverlappingCues(): void
    {
        $subtitle = $this->makeSubtitle([[0, 2, "a"], [1, 3, "b"], [3, 4, "c"]]);

        $this->assertSame(
            [[2, ValidationResult::RULE_MIN_GAP, 0.0, 0.5]],
            $this->toArrays($subtitle->validate(new ValidationRules(minGap: 0.5)))
        );
    }
}
