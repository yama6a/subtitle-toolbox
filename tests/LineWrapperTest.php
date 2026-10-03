<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class LineWrapperTest extends TestCase
{
    public function testWrapsIntoLinesOfEqualLength(): void
    {
        $this->assertSame(["The bakery opens", "at seven tomorrow."],
                          LineWrapper::wrap(["The bakery opens at seven tomorrow."], 20, 2));
    }


    public function testUsesTheFewestLinesThatFit(): void
    {
        $this->assertSame(["one two", "three four"], LineWrapper::wrap(["one two three four"], 10, 3));
    }


    public function testKeepsCjkTextWithoutSpacesAsOneWord(): void
    {
        $this->assertSame(["東京は今日とても暑いですね本当に"], LineWrapper::wrap(["東京は今日とても暑いですね本当に"], 10, 2));
        $this->assertFalse(LineWrapper::fits(["東京は今日とても暑いですね本当に"], 10, 2));
    }


    public function testBreaksCjkTextAtSpaces(): void
    {
        $this->assertSame(["東京は 今日とても", "暑いですね 本当に"], LineWrapper::wrap(["東京は 今日とても 暑いですね 本当に"], 10, 2));
        $this->assertSame(18, LineWrapper::characters(["東京は 今日とても", "暑いですね 本当に"]));
    }


    public function testKeepsAWordLongerThanTheLineWhole(): void
    {
        $lines = LineWrapper::wrap(["Supercalifragilisticexpialidocious is long"], 10, 2);

        $this->assertSame(["Supercalifragilisticexpialidocious", "is long"], $lines);
        $this->assertFalse(LineWrapper::fits($lines, 10, 2));
        $this->assertNull(LineWrapper::wrapToFit(["Supercalifragilisticexpialidocious is long"], 10, 2));
    }


    public function testClosesAndReopensItalicTagsAtTheLineBreak(): void
    {
        $this->assertSame(["<i>Are you coming</i>", "<i>to the party?</i>"],
                          LineWrapper::wrap(["<i>Are you coming to the party?</i>"], 16, 2));
    }


    public function testTagsCountNoCharactersAndEntitiesCountOne(): void
    {
        $this->assertSame([["text" => "<i>Tom", "length" => 3], ["text" => "&amp;</i>", "length" => 1], ["text" => "Jerry", "length" => 5]],
                          LineWrapper::words("<i>Tom &amp;</i> Jerry"));
        $this->assertTrue(LineWrapper::fits(['<font color="#ffff00">Tom &amp; Jerry</font>'], 11, 1));
        $this->assertFalse(LineWrapper::fits(['<font color="#ffff00">Tom &amp; Jerry</font>'], 10, 1));
        $this->assertSame(4, LineWrapper::characters(["<i>ab</i>", "c&amp;"]));
    }


    public function testJoinsDialogueLinesOnlyWhenAskedToKeepThem(): void
    {
        $lines = ["- Are you coming?", "- Yes, I am on my way now."];

        $this->assertSame($lines, LineWrapper::wrap($lines, 30, 2, true));
        $this->assertSame(["- Are you coming? -", "Yes, I am on my way now."], LineWrapper::wrap($lines, 30, 2));
    }


    public function testWrapToFitReturnsNullWhenDialogueLinesBreakALimit(): void
    {
        $this->assertSame(["- Hi.", "- Yes?"], LineWrapper::wrapToFit(["- Hi.", "- Yes?"], 20, 2));
        $this->assertNull(LineWrapper::wrapToFit(["- Are you coming?", "- Yes, I am on my way now."], 20, 2));
        $this->assertNull(LineWrapper::wrapToFit(["- Hi.", "- Yes?", "- No."], 20, 2));
    }


    public function testJoinsContinuedLinesBeforeWrapping(): void
    {
        $this->assertSame(["- We left early", "and came back late."],
                          LineWrapper::wrapToFit(["- We left", "early and came back late."], 20, 2));
    }


    public function testReturnsNoLinesForEmptyText(): void
    {
        $this->assertSame([], LineWrapper::wrap(["", " "], 20, 2));
        $this->assertSame([], LineWrapper::wrapToFit([""], 20, 2));
    }
}
