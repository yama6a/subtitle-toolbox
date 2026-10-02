<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class MarkupTest extends TestCase
{
    public function testStripAllTagsRemovesEveryTag(): void
    {
        $this->assertSame(
            "Fred: Hello world now",
            Markup::stripAllTags('<v Fred>Fred: <b>Hello</b> <font color="#ff0000">world</font> <00:01:02.500>now</v>')
        );
    }


    public function testStripAllTagsKeepsEscapedText(): void
    {
        $this->assertSame("&lt;b&gt;1 &amp; 2&lt;/b&gt;", Markup::stripAllTags("<i>&lt;b&gt;1 &amp; 2&lt;/b&gt;</i>"));
    }


    public function testKeepTagsKeepsOnlyTheGivenTags(): void
    {
        $this->assertSame(
            '<b>bold</b> <font color="#ff0000">red</font> struck',
            Markup::keepTags('<b>bold</b> <font color="#ff0000">red</font> <s>struck</s>', ["b", "font"])
        );
    }


    public function testKeepTagsWithCoreTagsKeepsCoreMarkup(): void
    {
        $text = '<v Fred><b>b</b><i>i</i><u>u</u><s>s</s><font color="#ff0000">c</font></v>';

        $this->assertSame($text . "x", Markup::keepTags($text . "<c.yellow>x</c>", Markup::CORE_TAGS));
    }


    public function testKeepTagsWithEmptyListStripsAllTags(): void
    {
        $this->assertSame("bold", Markup::keepTags("<b>bold</b>", []));
    }


    public function testStripAllTagsKeepsTextAfterQuotesInTags(): void
    {
        $this->assertSame("Hi.", Markup::stripAllTags("<v O'Neil>Hi.</v>"));
        $this->assertSame("We're out.", Markup::stripAllTags("<v O'Neil>We're out."));
        $this->assertSame("Two rolls.", Markup::stripAllTags('<v Mo "Baker>Two rolls.'));
        $this->assertSame('Take the "seeded" loaf.', Markup::stripAllTags('<v Sam "Ace" Reed>Take the "seeded" loaf.'));
        $this->assertSame("Red jam", Markup::stripAllTags("<font color='#ff0000'>Red</font> jam"));
        $this->assertSame("It's 'fine', \"really\"", Markup::stripAllTags("It's <i>'fine'</i>, \"really\""));
    }


    public function testStripAllTagsKeepsALessThanSignThatStartsNoTag(): void
    {
        $this->assertSame("I <3 bread", Markup::stripAllTags("I <3 bread"));
        $this->assertSame("a < b", Markup::stripAllTags("a < b"));
    }


    public function testKeepTagsKeepsTextAfterQuotesInTags(): void
    {
        $this->assertSame("<i>Hi.</i>", Markup::keepTags("<v O'Neil><i>Hi.</i>", ["i"]));
        $this->assertSame("<v O'Neil>We're out.", Markup::keepTags("<v O'Neil>We're out.", ["v"]));
        $this->assertSame('<v Mo "Baker>Two rolls.', Markup::keepTags('<v Mo "Baker>Two rolls.', Markup::CORE_TAGS));
        $this->assertSame("<font color='#ff0000'>Red</font> jam", Markup::keepTags("<font color='#ff0000'>Red</font> <s>jam</s>", ["font"]));
        $this->assertSame("It's x", Markup::keepTags("It's <c.loud>x</c>", ["i"]));
    }


    public function testKeepTagsComparesTagNamesWithoutCase(): void
    {
        $this->assertSame("<B>bold</B> <c.Loud>x</c.Loud> y", Markup::keepTags("<B>bold</B> <c.Loud>x</c.Loud> <i>y</i>", ["b", "C.loud"]));
    }


    public function testPlainLinesAndVisibleLengthKeepTextAfterQuotesInTags(): void
    {
        $this->assertSame(["We're out."], Markup::plainLines(["<v O'Neil>We're out.", "<i></i>"]));
        $this->assertSame(10, Markup::visibleLength("<v O'Neil>We're out."));
    }


    public function testDecodeEntities(): void
    {
        $this->assertSame("<b>1 & 2</b> \"it's\"", Markup::decodeEntities("&lt;b&gt;1 &amp; 2&lt;/b&gt; &quot;it&#39;s&quot;"));
        $this->assertSame("Caf\u{e9} \u{a0}", Markup::decodeEntities("Caf&eacute; &nbsp;"));
        $this->assertSame("plain & simple", Markup::decodeEntities("plain & simple"));
    }


    public function testStripThenDecodeKeepsEscapedTextAsText(): void
    {
        $this->assertSame("<b> is bold", Markup::decodeEntities(Markup::stripAllTags("<i>&lt;b&gt;</i> is bold")));
    }


    public function testEscapeText(): void
    {
        $this->assertSame("&lt;b&gt;1 &amp; 2&lt;/b&gt;", Markup::escapeText("<b>1 & 2</b>"));
        $this->assertSame("&amp;amp; stays text", Markup::escapeText("&amp; stays text"));
        $this->assertSame("\"it's\" Caf\u{e9}", Markup::escapeText("\"it's\" Caf\u{e9}"));
        $this->assertSame("", Markup::escapeText(""));
    }


    public function testEscapeTextKeepsLatin1Bytes(): void
    {
        $this->assertSame("caf\xE9 &amp; &lt;b&gt;", Markup::escapeText("caf\xE9 & <b>"));
    }


    public function testEscapeThenDecodeRoundTrips(): void
    {
        $text = "<b>1 & 2</b> &amp;";

        $this->assertSame($text, Markup::decodeEntities(Markup::stripAllTags(Markup::escapeText($text))));
    }


    public function testVisibleLengthLeavesOutTagsAndOuterSpacesAndCountsEntitiesAsOne(): void
    {
        $this->assertSame(11, Markup::visibleLength(" <i>Gr\u{fc}\u{df}e &amp; </i><00:00:01.000>Tee "));
        $this->assertSame(0, Markup::visibleLength("<b></b> "));
    }


    public function testCountCharactersCountsUtf8LettersOrBytesOfInvalidUtf8(): void
    {
        $this->assertSame(4, Markup::countCharacters("Caf\u{e9}"));
        $this->assertSame(2, Markup::countCharacters("\u{4f60}\u{597d}"));
        $this->assertSame(4, Markup::countCharacters("Caf\xe9"));
        $this->assertSame(0, Markup::countCharacters(""));
    }


    public function testWordTimestampRegexSplitsOnCoreWordTimestamps(): void
    {
        $this->assertSame(
            ["<b>One</b> ", "<00:00:01.500>", "two ", "<100:59:59.999>", ""],
            preg_split(Markup::WORD_TIMESTAMP_REGEX, "<b>One</b> <00:00:01.500>two <100:59:59.999>", -1, PREG_SPLIT_DELIM_CAPTURE)
        );
        $this->assertSame(0, preg_match(Markup::WORD_TIMESTAMP_REGEX, "<01:02.500> <00:60:00.000> <00:00:01.50> <0:00:01.500>"));
    }


    public function testCoreTimestampFormatsSecondsWithRoundedMilliseconds(): void
    {
        $this->assertSame("00:00:00.000", Markup::coreTimestamp(0.0));
        $this->assertSame("00:01:02.500", Markup::coreTimestamp(62.5));
        $this->assertSame("01:00:00.001", Markup::coreTimestamp(3600.0006));
        $this->assertSame("00:00:02.000", Markup::coreTimestamp(1.9996));
        $this->assertSame("100:00:00.000", Markup::coreTimestamp(360000.0));
    }


    public function testPlainLinesStripsDecodesTrimsAndDropsEmptyLines(): void
    {
        $this->assertSame(
            ["Tom & Jerry", "<not a tag>", "Caf\u{e9}"],
            Markup::plainLines([" <i>Tom &amp; Jerry</i> ", "<b></b>", "&lt;not a tag&gt;", "  ", "Caf&eacute;<00:00:01.000>"])
        );
        $this->assertSame([], Markup::plainLines([]));
    }
}
