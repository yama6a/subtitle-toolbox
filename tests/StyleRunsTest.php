<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class StyleRunsTest extends TestCase
{
    public function testNestsTheTagsInTheOrderFontBoldItalicUnderlineStrike(): void
    {
        $this->assertSame(
            "<font color=\"#ff0000\"><b><i><u><s>a</s></u></i></b></font>",
            StyleRuns::toMarkup([["a", ["color" => "#ff0000", "b" => true, "i" => true, "u" => true, "s" => true]]])
        );
    }


    public function testClosesTheTagsFromTheFirstStyleThatChanges(): void
    {
        $this->assertSame(
            "<font color=\"#ff0000\"><i>a</i><u>b</u></font><i>c</i>d",
            StyleRuns::toMarkup([
                ["a", ["color" => "#ff0000", "i" => true]],
                ["b", ["color" => "#ff0000", "u" => true]],
                ["c", ["i" => true]],
                ["d", []],
            ])
        );
    }


    public function testEscapesTextAndKeepsTheTagsOverARunOfOnlyWhiteSpace(): void
    {
        $this->assertSame(
            "<i>a &amp;   b </i>",
            StyleRuns::toMarkup([["a & ", ["i" => true]], ["", []], ["  ", ["b" => true]], ["b", ["i" => true]], [" ", []]])
        );
    }


    public function testSpacesOutsidePutsWhiteSpaceAtBothEndsOfARunOutsideItsTags(): void
    {
        $this->assertSame(
            " <i>a</i>  <b>b c</b> \t",
            StyleRuns::toMarkup([[" a ", ["i" => true]], [" ", []], ["b", ["b" => true]], [" c \t", ["b" => true]]], true)
        );
        $this->assertSame("  ", StyleRuns::toMarkup([["  ", ["i" => true]]], true));
    }
}
