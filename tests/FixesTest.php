<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use InvalidArgumentException;
use SubtitleToolbox\Tests\Support\TestSubtitles;
use SubtitleToolbox\Validation\ValidationRules;

class FixesTest extends \PHPUnit\Framework\TestCase
{
    private function wrap(string $text, int $maxCharactersPerLine, int $maxLines = 2): array
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, $text));
        $subtitle->wrapLines($maxCharactersPerLine, $maxLines);

        return $subtitle->getCues()[0]->getLines();
    }


    public function testRealFileWithOverlapsAndShortCues(): void
    {
        $content  = file_get_contents(__DIR__ . "/files/fixes/own_overlaps_and_short_cues.srt");
        $subtitle = Subtitle::fromString($content, Format::SubRip);
        $cues     = $subtitle->getCues();

        $this->assertCount(9, $cues);
        $this->assertSame([1.0, 3.2, "Good morning, everyone."], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([20.0, 20.4, "Tom &amp; Jerry &lt;3 coffee."], [$cues[8]->getStart(), $cues[8]->getEnd(), $cues[8]->getText()]);

        $subtitle->fixOverlaps(0.083)->extendShortCues(0.833, 0.083)->wrapLines(42);

        $this->assertSame(file_get_contents(__DIR__ . "/files/fixes/own_overlaps_and_short_cues_fixed.srt"),
                          $subtitle->toString(Format::SubRip));
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }


    public function testFixOverlapsKeepsTwoFramesGapAt24Fps(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 5.2], [5.1, 7]]);

        $this->assertSame($subtitle, $subtitle->fixOverlaps((new FrameRate(24))->framesToSeconds(2)));
        $this->assertSame([[1.0, 5.017], [5.1, 7.0]], TestSubtitles::times($subtitle));
    }


    public function testFixOverlapsWithoutGap(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 5.2], [5.1, 7], [7, 8]]);

        $subtitle->fixOverlaps();

        $this->assertSame([[1.0, 5.1], [5.1, 7.0], [7.0, 8.0]], TestSubtitles::times($subtitle));
    }


    public function testFixOverlapsWidensGapsThatAreTooSmall(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 5.05], [5.1, 7]]);

        $subtitle->fixOverlaps(0.1);

        $this->assertSame([[1.0, 5.0], [5.1, 7.0]], TestSubtitles::times($subtitle));
    }


    public function testFixOverlapsUsesTheNextCueInTimeOrder(): void
    {
        $subtitle = (new Subtitle())->addCues([new SubtitleCue(1, 2, "late"), new SubtitleCue(3, 4, "early")]);
        $subtitle->getCues()[0]->setStart(10)->setEnd(14);
        $subtitle->getCues()[1]->setStart(1)->setEnd(12);

        $subtitle->fixOverlaps();

        $this->assertSame([[10.0, 14.0], [1.0, 10.0]], TestSubtitles::times($subtitle));
    }


    public function testFixOverlapsNeverMovesAStartTime(): void
    {
        $subtitle = TestSubtitles::fromTimes([[5, 6], [5, 7], [5.05, 8]]);

        $subtitle->fixOverlaps(0.1);

        $this->assertSame([[5.0, 5.0], [5.0, 5.0], [5.05, 8.0]], TestSubtitles::times($subtitle));
    }


    public function testFixOverlapsTrimsCuesWithTheSameStartAgainstTheNextLaterStart(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 4], [1, 3], [2, 5], [6, 7], [6, 8]]);

        $subtitle->fixOverlaps();

        $this->assertSame([[1.0, 2.0], [1.0, 2.0], [2.0, 5.0], [6.0, 7.0], [6.0, 8.0]],
                          TestSubtitles::times($subtitle));
    }


    public function testFixOverlapsWithNegativeGapThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The minimum gap must not be negative, got -1.");
        TestSubtitles::fromTimes([[1, 2]])->fixOverlaps(-1);
    }


    public function testExtendShortCues(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 1.3], [3.3, 5]]);

        $this->assertSame($subtitle, $subtitle->extendShortCues(0.833));
        $this->assertSame([[1.0, 1.833], [3.3, 5.0]], TestSubtitles::times($subtitle));
    }


    public function testExtendShortCuesStopsBeforeTheNextCue(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 1.3], [1.5, 1.6], [1.6, 3]]);

        $subtitle->extendShortCues(1, 0.1);

        $this->assertSame([[1.0, 1.4], [1.5, 1.6], [1.6, 3.0]], TestSubtitles::times($subtitle));
    }


    public function testExtendShortCuesNeverShortensACue(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 1.3], [1.2, 1.4], [2, 5]]);

        $subtitle->extendShortCues(1, 0.1);

        $this->assertSame([[1.0, 1.3], [1.2, 1.9], [2.0, 5.0]], TestSubtitles::times($subtitle));
    }


    public function testExtendShortCuesDoesNotExtendCuesWithTheSameStart(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 1.2], [1, 1.3], [5, 6]]);

        $subtitle->extendShortCues(1);

        $this->assertSame([[1.0, 1.2], [1.0, 1.3], [5.0, 6.0]], TestSubtitles::times($subtitle));
    }


    public function testExtendShortCuesExtendsTheLastCue(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 1.2]]);

        $subtitle->extendShortCues(2, 0.5);

        $this->assertSame([[1.0, 3.0]], TestSubtitles::times($subtitle));
    }


    public function testExtendShortCuesWithZeroDurationThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The minimum duration must be greater than 0, got 0.");
        TestSubtitles::fromTimes([[1, 2]])->extendShortCues(0);
    }


    public function testWrapLinesBalancesTwoLines(): void
    {
        $this->assertSame(["This is a very long subtitle line", "that does not fit on the screen"],
                          $this->wrap("This is a very long subtitle line that does not fit on the screen", 42));
    }


    public function testWrapLinesReturnsSubtitle(): void
    {
        $subtitle = new Subtitle();

        $this->assertSame($subtitle, $subtitle->wrapLines(42));
    }


    public function testWrapLinesKeepsCuesThatFit(): void
    {
        $this->assertSame(["Short line,", "and another one"], $this->wrap("Short line,\nand another one", 42));
    }


    public function testWrapLinesJoinsCuesWithTooManyLines(): void
    {
        $this->assertSame(["One two three", "four five six"], $this->wrap("One\ntwo three\nfour\nfive six", 15));
    }


    public function testWrapLinesUsesThreeLinesWhenTwoDoNotFit(): void
    {
        $this->assertSame(["one two three", "four five six", "seven eight"],
                          $this->wrap("one two three four five six seven eight", 15, 3));
    }


    public function testWrapLinesKeepsMaxLinesWhenTheTextDoesNotFit(): void
    {
        $this->assertSame(["one two three four", "five six seven eight"],
                          $this->wrap("one two three four five six seven eight", 10));
    }


    public function testWrapLinesDoesNotBreakAWordLongerThanTheLine(): void
    {
        $this->assertSame(["Donaudampfschifffahrtsgesellschaft"], $this->wrap("Donaudampfschifffahrtsgesellschaft", 10));
    }


    public function testWrapLinesClosesAndReopensTagsAtTheBreak(): void
    {
        $this->assertSame(["<i>This is a very long <b>subtitle line</b></i>", "<i>that does not fit</i> on the screen"],
                          $this->wrap("<i>This is a very long <b>subtitle line</b> that does not fit</i> on the screen", 42));
        $this->assertSame(["<i><b>This is a very long subtitle line</b></i>", "<i><b>that does not fit on the screen</b></i>"],
                          $this->wrap("<i><b>This is a very long subtitle line that does not fit on the screen</b></i>", 42));
    }


    public function testWrapLinesDoesNotBreakInsideATag(): void
    {
        $this->assertSame(['<font color="#ff0000">Red text that is long enough</font>',
                           '<font color="#ff0000">to need a break somewhere</font>'],
                          $this->wrap('<font color="#ff0000">Red text that is long enough to need a break somewhere</font>', 30));
        $this->assertSame(["<v Fred>Hello there my friend,</v>", "<v Fred>how are you doing today"],
                          $this->wrap("<v Fred>Hello there my friend, how are you doing today", 30));
    }


    public function testWrapLinesDoesNotCountTagsAndCountsEntitiesAsOneCharacter(): void
    {
        $this->assertSame(["<b>Tom</b> &amp; <i>Jerry</i> &lt;3 <00:00:01.500>coffee"],
                          $this->wrap("<b>Tom</b> &amp; <i>Jerry</i> &lt;3 <00:00:01.500>coffee", 21));
        $this->assertSame(["Tom &amp; Jerry", "&lt;3 coffee"], $this->wrap("Tom &amp; Jerry &lt;3 coffee", 20));
    }


    public function testWrapLinesCountsMultibyteCharactersOnce(): void
    {
        $this->assertSame(["Größere Äpfel überqueren übermütig"],
                          $this->wrap("Größere Äpfel überqueren übermütig", 34));
        $this->assertSame(["Größere Äpfel überqueren übermütig", "die Straße, während Bäume grünen."],
                          $this->wrap("Größere Äpfel überqueren übermütig die Straße, während Bäume grünen.", 42));
    }


    public function testWrapLinesBreaksArabicTextAtSpaces(): void
    {
        $this->assertSame(["هذا سطر طويل من الترجمة لا", "يتسع على الشاشة بشكل جيد"],
                          $this->wrap("هذا سطر طويل من الترجمة لا يتسع على الشاشة بشكل جيد", 42));
    }


    public function testWrapLinesDoesNotBreakTextWithoutSpaces(): void
    {
        $text = "これは字幕の長い行で、画面に収まりません。これは字幕の長い行です。";

        $this->assertSame([$text], $this->wrap($text, 16));
        $this->assertSame(["これは字幕の長い行で、", "画面に収まりません。"], $this->wrap("これは字幕の長い行で、\n画面に収まりません。", 16));
    }


    public function testWrapLinesCountsBytesOfInvalidUtf8(): void
    {
        $this->assertSame(["caf\xE9", "au lait"], $this->wrap("caf\xE9 au lait", 8));
    }


    public function testWrapLinesWithZeroMaxCharsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The maximum characters per line and the maximum lines must be at least 1, got 0 and 2.");
        (new Subtitle())->wrapLines(0);
    }


    public function testUnwrapLines(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "<i>Please take a seat,</i>\nthe meeting starts."));
        $subtitle->addCue(new SubtitleCue(3, 4, "One line"));
        $subtitle->addCue(new SubtitleCue(5, 6, ""));

        $this->assertSame($subtitle, $subtitle->unwrapLines());
        $this->assertSame([["<i>Please take a seat,</i> the meeting starts."], ["One line"], []],
                          array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $subtitle->getCues()));
    }
}
