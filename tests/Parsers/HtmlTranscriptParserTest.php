<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class HtmlTranscriptParserTest extends TestCase
{
    private static function cues(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $subtitle->getCues());
    }


    public function testReadsAFullDocumentWithParagraphsUpToTheNextTime(): void
    {
        $html = "<!DOCTYPE html>\r\n<html>\r\n<head><title>Episode 4</title></head>\r\n<body>\r\n" .
                "  <cite>Anna:</cite>\r\n  <time>0:05</time>\r\n  <p class=\"line\">Hello<br>there.</p>\r\n  <p>Come <b>in</b>.</p>\r\n" .
                "  <time>1:02:03.5</time>\r\n  <P>&lt;3 &amp; bye</P>\r\n</body>\r\n</html>\r\n";

        $this->assertSame([
            [5.0, 3723.5, ["<v Anna>Hello", "there.", "Come in."]],
            [3723.5, 3733.5, ["&lt;3 &amp; bye"]],
        ], self::cues((new HtmlTranscriptParser())->parse($html)));
    }


    public function testACiteNamesOnlyTheNextParagraph(): void
    {
        $html = "<cite>Dr. O'Neil :</cite><time>0:00</time><p>Hi.</p><time>0:02</time><p>Bye.</p>";

        $this->assertSame([[0.0, 2.0, ["<v Dr. O&#39;Neil>Hi."]], [2.0, 5.0, ["Bye."]]],
                          self::cues((new HtmlTranscriptParser(3))->parse($html)));
    }


    public function testThrowsForAParagraphWithoutTime(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The paragraph has no <time>. (line 2)");

        (new HtmlTranscriptParser())->parse("<time>0:00</time><p>Hi.</p>\n<cite>Ben:</cite>\n<p>Bye.</p>");
    }


    public function testThrowsForABadTime(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The time \"soon\" is not valid. (line 3)");

        (new HtmlTranscriptParser())->parse("<cite>Ben:</cite>\n\n<time>soon</time>\n<p>Bye.</p>");
    }


    public function testSkipsABadParagraphInLenientMode(): void
    {
        $parser   = (new HtmlTranscriptParser())->setLenient();
        $subtitle = $parser->parse("<time>0:00</time><p>Hi.</p>\n<time>0:61</time><p>Oops.</p>\n<time>0:04</time><p>Bye.</p>");

        $this->assertSame([[0.0, 4.0, ["Hi."]], [4.0, 14.0, ["Bye."]]], self::cues($subtitle));
        $this->assertSame([["The time \"0:61\" is not valid. (line 2)", 2, 1, ParseWarning::SKIPPED]], array_map(
            fn (ParseWarning $warning): array => [$warning->message, $warning->lineNumber, $warning->blockIndex, $warning->action],
            $parser->getWarnings()
        ));
    }
}
