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
}
