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


    public function testTextRulesOnOwnFile(): void
    {
        $subtitle = $this->parseFile("validation/own_text_checks.vtt", WebVttParser::class);
        $rules    = new ValidationRules(
            noDoubleSpaces: true,
            noLeadingOrTrailingSpaces: true,
            noUnbalancedTags: true,
            dialogueDashStyle: "- ",
            maxSpeakersPerCue: 2,
            maxWordsPerMinute: 180,
            minSecondsPerWord: 0.3,
            noAllCapsLines: true,
        );

        $this->assertCount(8, $subtitle->getCues());
        $this->assertSame([
            [0, ValidationResult::RULE_NO_DOUBLE_SPACES, 1, null],
            [0, ValidationResult::RULE_NO_UNBALANCED_TAGS, 1, null],
            [0, ValidationResult::RULE_DIALOGUE_DASH_STYLE, 1, null],
            [0, ValidationResult::RULE_MAX_SPEAKERS_PER_CUE, 3, 2],
            [0, ValidationResult::RULE_MAX_WORDS_PER_MINUTE, 480.0, 180.0],
            [0, ValidationResult::RULE_MIN_SECONDS_PER_WORD, 0.125, 0.3],
            [1, ValidationResult::RULE_NO_DOUBLE_SPACES, 1, null],
            [1, ValidationResult::RULE_NO_LEADING_OR_TRAILING_SPACES, 2, null],
            [3, ValidationResult::RULE_NO_ALL_CAPS_LINES, 1, null],
            [4, ValidationResult::RULE_DIALOGUE_DASH_STYLE, 2, null],
            [6, ValidationResult::RULE_NO_UNBALANCED_TAGS, 2, null],
        ], $this->toArrays($subtitle->validate($rules)));
    }


    public function testTextRulesAreOffByDefault(): void
    {
        $subtitle = $this->parseFile("validation/own_text_checks.vtt", WebVttParser::class);

        $this->assertSame([], $subtitle->validate(new ValidationRules()));
    }


    public function testIssueExampleCue(): void
    {
        $subtitle = $this->makeSubtitle([[10, 11, ["-Where are you?\u{00A0} <i>Home", "- Wait.", "- Now!"]]]);
        $rules    = new ValidationRules(
            noDoubleSpaces: true,
            noUnbalancedTags: true,
            dialogueDashStyle: "- ",
            maxSpeakersPerCue: 2,
            maxWordsPerMinute: 180,
            minSecondsPerWord: 0.3,
        );

        $this->assertSame([
            [0, ValidationResult::RULE_NO_DOUBLE_SPACES, 1, null],
            [0, ValidationResult::RULE_NO_UNBALANCED_TAGS, 1, null],
            [0, ValidationResult::RULE_DIALOGUE_DASH_STYLE, 1, null],
            [0, ValidationResult::RULE_MAX_SPEAKERS_PER_CUE, 3, 2],
            [0, ValidationResult::RULE_MAX_WORDS_PER_MINUTE, 480.0, 180.0],
            [0, ValidationResult::RULE_MIN_SECONDS_PER_WORD, 0.125, 0.3],
        ], $this->toArrays($subtitle->validate($rules)));
    }


    public function testWordsMatchSubtitleStatistics(): void
    {
        $subtitle = $this->parseFile("vtt/real/webvttpy_netflix.vtt", WebVttParser::class);
        $words    = 0;
        foreach ($subtitle->validate(new ValidationRules(maxWordsPerMinute: 0.001)) as $result) {
            $cue    = $subtitle->getCues()[$result->getCueIndex()];
            $words += (int)round($result->getValue() * round($cue->getEnd() - $cue->getStart(), 3) / 60);
        }

        $this->assertSame(SubtitleStatistics::of($subtitle)->getWordCount(), $words);
    }


    public function testBbcPresetValues(): void
    {
        $rules = ValidationRules::bbc();

        $this->assertSame(37, $rules->maxCharactersPerLine);
        $this->assertSame(180.0, $rules->maxWordsPerMinute);
        $this->assertSame(0.3, $rules->minSecondsPerWord);
        $this->assertNull($rules->maxCharactersPerSecond);
        $this->assertNull($rules->maxLinesPerCue);
        $this->assertFalse($rules->noOverlap);
    }


    public function testBbcPresetOnRealWebVttFile(): void
    {
        $subtitle = $this->parseFile("vtt/real/w3c_voices.vtt", WebVttParser::class);

        $this->assertSame([
            [1, ValidationResult::RULE_MAX_CHARACTERS_PER_LINE, 55, 37],
            [1, ValidationResult::RULE_MAX_WORDS_PER_MINUTE, 200.0, 180.0],
            [2, ValidationResult::RULE_MAX_CHARACTERS_PER_LINE, 39, 37],
            [2, ValidationResult::RULE_MAX_WORDS_PER_MINUTE, 210.0, 180.0],
            [2, ValidationResult::RULE_MIN_SECONDS_PER_WORD, 2 / 7, 0.3],
            [6, ValidationResult::RULE_MAX_WORDS_PER_MINUTE, 240.0, 180.0],
            [6, ValidationResult::RULE_MIN_SECONDS_PER_WORD, 0.25, 0.3],
            [7, ValidationResult::RULE_MAX_CHARACTERS_PER_LINE, 61, 37],
            [7, ValidationResult::RULE_MAX_WORDS_PER_MINUTE, 260.0, 180.0],
            [7, ValidationResult::RULE_MIN_SECONDS_PER_WORD, 3 / 13, 0.3],
            [8, ValidationResult::RULE_MAX_WORDS_PER_MINUTE, 200.0, 180.0],
            [9, ValidationResult::RULE_MAX_CHARACTERS_PER_LINE, 47, 37],
            [9, ValidationResult::RULE_MAX_WORDS_PER_MINUTE, 270.0, 180.0],
            [9, ValidationResult::RULE_MIN_SECONDS_PER_WORD, 2 / 9, 0.3],
            [10, ValidationResult::RULE_MAX_CHARACTERS_PER_LINE, 42, 37],
            [12, ValidationResult::RULE_MAX_CHARACTERS_PER_LINE, 58, 37],
            [12, ValidationResult::RULE_MAX_WORDS_PER_MINUTE, 288.0, 180.0],
            [12, ValidationResult::RULE_MIN_SECONDS_PER_WORD, 2.5 / 12, 0.3],
        ], $this->toArrays($subtitle->validate(ValidationRules::bbc())));
    }


    public function testSpeakersFromVoicesAndDashesOnRealWebVttFiles(): void
    {
        $voices  = $this->parseFile("vtt/real/w3c_voices.vtt", WebVttParser::class);
        $netflix = $this->parseFile("vtt/real/webvttpy_netflix.vtt", WebVttParser::class);

        $this->assertSame([], $voices->validate(new ValidationRules(maxSpeakersPerCue: 1)));
        $this->assertSame(
            [[0, ValidationResult::RULE_MAX_SPEAKERS_PER_CUE, 2, 1]],
            $this->toArrays($this->makeSubtitle([[0, 1, ["<v Anna>Hi", "<v.loud Tom>Hello", "<v Anna>Bye"]]])
                ->validate(new ValidationRules(maxSpeakersPerCue: 1)))
        );
        $this->assertSame(
            [[17, ValidationResult::RULE_MAX_SPEAKERS_PER_CUE, 2, 1]],
            $this->toArrays($netflix->validate(new ValidationRules(maxSpeakersPerCue: 1)))
        );
        $this->assertSame([], $netflix->validate(new ValidationRules(dialogueDashStyle: "- ")));
        $this->assertSame(
            [[17, ValidationResult::RULE_DIALOGUE_DASH_STYLE, 2, null]],
            $this->toArrays($netflix->validate(new ValidationRules(dialogueDashStyle: "\u{2013} ")))
        );
    }


    public function testUnbalancedTagsOnRealSubRipFile(): void
    {
        $subtitle = $this->parseFile("srt/real/own_styled.srt", SubRipParser::class);

        $this->assertSame(
            [[7, ValidationResult::RULE_NO_UNBALANCED_TAGS, 1, null]],
            $this->toArrays($subtitle->validate(new ValidationRules(noUnbalancedTags: true)))
        );
    }


    public function testUnbalancedTagsAcrossLinesAndOpenVoices(): void
    {
        $subtitle = $this->makeSubtitle([
            [0, 1, ["<v Anna><i>Over two", "lines</i>"]],
            [1, 2, ["<b><i>Crossed</b></i>"]],
            [2, 3, ["Stray</u> and <font color=\"#ff0000\">open"]],
            [3, 4, ["<c.red>Other</c> tags and <00:00:03.500>word timestamps"]],
            [4, 5, ["Text &lt;i&gt; that looks like a tag"]],
        ]);

        $this->assertSame([
            [1, ValidationResult::RULE_NO_UNBALANCED_TAGS, 2, null],
            [2, ValidationResult::RULE_NO_UNBALANCED_TAGS, 2, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(noUnbalancedTags: true))));
    }


    public function testSpacesInsideTagsAndNonBreakingSpaces(): void
    {
        $subtitle = $this->makeSubtitle([
            [0, 1, ["One <i> two</i>", "<b>three </b>"]],
            [1, 2, ["Four\u{00A0}\u{00A0}five", "\u{00A0}six"]],
            [2, 3, ["No <i>problem</i> here"]],
        ]);

        $this->assertSame([
            [0, ValidationResult::RULE_NO_DOUBLE_SPACES, 1, null],
            [0, ValidationResult::RULE_NO_LEADING_OR_TRAILING_SPACES, 1, null],
            [1, ValidationResult::RULE_NO_DOUBLE_SPACES, 1, null],
            [1, ValidationResult::RULE_NO_LEADING_OR_TRAILING_SPACES, 1, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(noDoubleSpaces: true, noLeadingOrTrailingSpaces: true))));
    }


    public function testDialogueDashStyle(): void
    {
        $subtitle = $this->makeSubtitle([
            [0, 1, ["- Yes.", "-No."]],
            [1, 2, ["<i>-Maybe.</i>", "\u{2014}Never."]],
            [2, 3, ["-20 degrees.", "--- a line", "- "]],
        ]);

        $this->assertSame([
            [0, ValidationResult::RULE_DIALOGUE_DASH_STYLE, 1, null],
            [1, ValidationResult::RULE_DIALOGUE_DASH_STYLE, 2, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(dialogueDashStyle: "- "))));
        $this->assertSame([
            [0, ValidationResult::RULE_DIALOGUE_DASH_STYLE, 1, null],
            [1, ValidationResult::RULE_DIALOGUE_DASH_STYLE, 1, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(dialogueDashStyle: "-"))));
    }


    public function testWordRulesSkipEmptyCuesAndMatchMilliseconds(): void
    {
        $subtitle = $this->makeSubtitle([[0, 1.2, "Four words right here"], [2, 2, "Now"], [3, 4, ""], [5, 6.199, "Four words too fast"]]);

        $this->assertSame([
            [1, ValidationResult::RULE_MAX_WORDS_PER_MINUTE, INF, 200.0],
            [1, ValidationResult::RULE_MIN_SECONDS_PER_WORD, 0.0, 0.3],
            [3, ValidationResult::RULE_MAX_WORDS_PER_MINUTE, 240 / 1.199, 200.0],
            [3, ValidationResult::RULE_MIN_SECONDS_PER_WORD, 1.199 / 4, 0.3],
        ], $this->toArrays($subtitle->validate(new ValidationRules(maxWordsPerMinute: 200, minSecondsPerWord: 0.3))));
    }


    public function testAllowedCharactersAsStringAndCharacterClass(): void
    {
        $subtitle = $this->parseFile("validation/own_text_checks.vtt", WebVttParser::class);
        // BBC Subtitle Guidelines 9.3.1, characters for broadcast.
        $broadcast = "[A-Za-z0-9!)(,.?:\\-><&@#%+*=/\u{00A3}\$\u{00A2}\u{00A5}\u{00A9}\u{00AE}\u{00BC}\u{00BD}\u{00BE}\u{2122}'\"]";

        $this->assertSame([
            [1, ValidationResult::RULE_ALLOWED_CHARACTERS, 1, null],
            [3, ValidationResult::RULE_ALLOWED_CHARACTERS, 2, null],
            [4, ValidationResult::RULE_ALLOWED_CHARACTERS, 2, null],
            [5, ValidationResult::RULE_ALLOWED_CHARACTERS, 4, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(allowedCharacters: $broadcast))));
        $this->assertSame(
            [[0, ValidationResult::RULE_ALLOWED_CHARACTERS, 5, null]],
            $this->toArrays($this->makeSubtitle([[0, 1, "Caf\u{00E9} a/b [c]"]])
                ->validate(new ValidationRules(allowedCharacters: "Cafab")))
        );
        $this->assertSame([], $this->makeSubtitle([[0, 1, "a/b"]])->validate(new ValidationRules(allowedCharacters: "[a-z/]")));
    }


    public function testAllCapsLinesSkipVoiceNamesAndBrackets(): void
    {
        $subtitle = $this->makeSubtitle([
            [0, 1, ["<v ANNA>Hello.", "[BELL RINGS]", "(SHOUTS) Stop!", "I"]],
            [1, 2, ["STOP THE TRAIN!", "<i>NOW</i>", "\u{00C4}RGER"]],
            [2, 3, ["(SHOUTING) STOP"]],
        ]);

        $this->assertSame([
            [1, ValidationResult::RULE_NO_ALL_CAPS_LINES, 3, null],
            [2, ValidationResult::RULE_NO_ALL_CAPS_LINES, 1, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(noAllCapsLines: true))));
    }


    public function testTextRulesOnInvalidUtf8(): void
    {
        $subtitle = $this->makeSubtitle([[0, 1, "CAF\xC9 \xC9T\xC9"]]);
        $rules    = new ValidationRules(noDoubleSpaces: true, allowedCharacters: "[A-Za-z]", noAllCapsLines: true);

        $this->assertSame([
            [0, ValidationResult::RULE_ALLOWED_CHARACTERS, 3, null],
            [0, ValidationResult::RULE_NO_ALL_CAPS_LINES, 1, null],
        ], $this->toArrays($subtitle->validate($rules)));
    }
}
