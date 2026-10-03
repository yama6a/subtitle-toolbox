<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class PodcastTranscriptParserTest extends TestCase
{
    private static function cues(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $subtitle->getCues());
    }


    private static function words(array $words): string
    {
        $segments = array_map(fn (array $word): array => ["speaker" => $word[0], "startTime" => $word[1], "endTime" => $word[2], "body" => $word[3]],
                              $words);

        return json_encode(["version" => "1.0.0", "segments" => $segments]);
    }


    public function testJoinsWordsBySpeakerAndSentenceEnd(): void
    {
        $json = self::words([
            ["Anna", 0, 0.4, "Hello"], ["Anna", 0.5, 0.9, "there."], ["Anna", 1, 1.4, "Come"], ["Anna", 1.5, 1.9, "in!"],
            ["Ben", 2, 2.4, "Thanks"], ["Anna", 2.5, 2.9, "Sit"], ["Anna", 3, 3.4, "\"down.\""], ["Anna", 3.5, 3.9, "Tea"],
        ]);

        $this->assertSame([
            [0.0, 0.9, "<v Anna>Hello there."],
            [1.0, 1.9, "<v Anna>Come in!"],
            [2.0, 2.4, "<v Ben>Thanks"],
            [2.5, 3.4, "<v Anna>Sit \"down.\""],
            [3.5, 3.9, "<v Anna>Tea"],
        ], self::cues((new PodcastTranscriptParser())->parse($json)));
    }


    public function testKeepsSegmentsWithTheOption(): void
    {
        $json   = self::words([["Anna", 0, 0.4, "Hello"], ["Anna", 0.5, 0.9, "there."]]);
        $parser = new PodcastTranscriptParser([PodcastTranscriptParser::OPTION_KEEP_SEGMENTS => true]);

        $this->assertSame([[0.0, 0.4, "<v Anna>Hello"], [0.5, 0.9, "<v Anna>there."]], self::cues($parser->parse($json)));
    }


    public function testWritesWordTimestampsWithTheOption(): void
    {
        $json   = self::words([["Anna", 0, 0.4, "Hello"], ["Anna", 0.5, 0.9, "there."], ["Anna", 1, 2, "Bye."]]);
        $parser = new PodcastTranscriptParser([PodcastTranscriptParser::OPTION_WORD_TIMESTAMPS => true]);

        $this->assertSame([[0.0, 0.9, "<v Anna><00:00:00.000>Hello <00:00:00.500>there."], [1.0, 2.0, "<v Anna>Bye."]],
                          self::cues($parser->parse($json)));
    }


    public function testNeverJoinsASegmentOfSeveralWords(): void
    {
        $json = '{"segments": [{"startTime": 0, "endTime": 2, "body": "Where are"}, {"startTime": 2, "endTime": 3, "body": "you"},' .
                ' {"startTime": 3, "endTime": 4, "body": "going?"}]}';

        $this->assertSame([[0.0, 2.0, "Where are"], [2.0, 4.0, "you going?"]], self::cues((new PodcastTranscriptParser())->parse($json)));
    }


    public function testEndsASegmentWithoutEndTimeAtTheNextLaterStart(): void
    {
        $json   = '{"segments": [{"startTime": 1, "body": "One two"}, {"startTime": 1, "body": "Three four"}, {"startTime": 5, "body": "Five six"}]}';
        $parser = new PodcastTranscriptParser([], 3);

        $this->assertSame([[1.0, 5.0, "One two"], [1.0, 5.0, "Three four"], [5.0, 8.0, "Five six"]], self::cues($parser->parse($json)));
    }


    public function testEscapesTextAndSpeakers(): void
    {
        $json = '{"segments": [{"speaker": "Dr. O\'Neil", "startTime": 0, "endTime": 1, "body": " Fish & <chips>\n "}]}';

        $this->assertSame([[0.0, 1.0, "<v Dr. O&#39;Neil>Fish &amp; &lt;chips&gt;"]], self::cues((new PodcastTranscriptParser())->parse($json)));
    }


    public function testSkipsSegmentsWithoutBody(): void
    {
        $json = '{"segments": [{"speaker": "Anna", "startTime": 0}, {"startTime": 1, "endTime": 2, "body": " "}, {"startTime": 2, "endTime": 3, "body": "Hi"}]}';

        $this->assertSame([[2.0, 3.0, "Hi"]], self::cues((new PodcastTranscriptParser())->parse($json)));
    }


    public function testKeepsOtherFieldsInTheFormatData(): void
    {
        $json     = '{"version": "1.0.0", "segments": [{"startTime": 0, "endTime": 1, "body": "Hi", "confidence": 0.9}], "language": "en"}';
        $subtitle = (new PodcastTranscriptParser())->parse("\u{FEFF}" . $json);

        $this->assertSame(["version" => "1.0.0", "language" => "en"], $subtitle->getFormatData("podcast"));
        $this->assertSame(["confidence" => 0.9], $subtitle->getCues()[0]->getFormatData("podcast"));
    }


    public function testThrowsForABadField(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The field segments[1].startTime must be a number.");

        (new PodcastTranscriptParser())->parse('{"segments": [{"startTime": 0, "body": "Hi"}, {"startTime": "0:01", "body": "Bye"}]}');
    }


    public function testSkipsABadSegmentInLenientMode(): void
    {
        $parser   = (new PodcastTranscriptParser())->setLenient();
        $subtitle = $parser->parse('{"segments": [{"startTime": 0, "endTime": 1, "body": "Hi"}, {"startTime": 1, "body": 7}, "x"]}');

        $this->assertSame([[0.0, 1.0, "Hi"]], self::cues($subtitle));
        $this->assertSame(
            [["The field segments[1].body must be a string.", 1, '{"startTime":1,"body":7}'], ["The field segments[2] must be an object.", 2, '"x"']],
            array_map(fn (ParseWarning $warning): array => [$warning->message, $warning->blockIndex, $warning->block[0]], $parser->getWarnings())
        );
    }
}
