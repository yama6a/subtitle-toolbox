<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;

class JsonFormatRulesTest extends TestCase
{
    /**
     * Each case holds the text "B\xFFd" in the field that becomes the text of the first cue.
     */
    public static function invalidUtf8(): array
    {
        return [
            "library JSON"        => [new JsonParser(), '{"version": 1, "cues": [{"start": 0, "end": 1, "lines": ["B' . "\xFF" . 'd"]}]}'],
            "Podcast transcript"  => [new PodcastTranscriptParser(), '{"version": "1.0.0", "segments": [{"startTime": 0, "endTime": 1, "body": "B' . "\xFF" . 'd"}]}'],
            "Podcast chapters"    => [new PodcastChaptersParser(), '{"version": "1.2.0", "chapters": [{"startTime": 0, "title": "B' . "\xFF" . 'd"}]}'],
            "Whisper"             => [new WhisperJsonParser(), '{"segments": [{"start": 0, "end": 1, "text": "B' . "\xFF" . 'd"}]}'],
            "Deepgram"            => [new DeepgramParser(), '{"results": {"channels": [{"alternatives": [{"words": [{"word": "B' . "\xFF" . 'd", "start": 0, "end": 1}]}]}]}}'],
            "YouTube json3"       => [new YouTubeTimedTextParser(), '{"events": [{"tStartMs": 0, "dDurationMs": 1000, "segs": [{"utf8": "B' . "\xFF" . 'd"}]}]}'],
        ];
    }


    #[DataProvider("invalidUtf8")]
    public function testReadsAnInvalidUtf8ByteAsTheReplacementCharacter(SubtitleParser $parser, string $json): void
    {
        $cues = $parser->parse($json, new ReadOptions())->getCues();

        $this->assertSame("B\u{FFFD}d", $cues[0]->getText());
    }


    /**
     * Each case has a first cue with a negative time and a second cue from 2 to 3 seconds with the text "Ok".
     */
    public static function negativeTimes(): array
    {
        return [
            "Whisper"            => [new WhisperJsonParser(), '{"segments": [{"start": -5, "end": 1, "text": "Hi"}, {"start": 2, "end": 3, "text": "Ok"}]}',
                                     "The field segments[0].start must be a time of 0 or more."],
            "AssemblyAI"         => [new AssemblyAiParser(), '{"utterances": [{"start": 1000, "end": -1000, "text": "Hi"}, {"start": 2000, "end": 3000, "text": "Ok"}]}',
                                     "The field utterances[0].end must be a time."],
            "Google"             => [new GoogleSpeechParser(), '{"results": [{"alternatives": [{"transcript": "Hi"}], "resultEndTime": "-1s"}, '
                                     . '{"alternatives": [{"transcript": "Ok", "words": [{"word": "Ok", "startTime": "2s", "endTime": "3s"}]}]}]}',
                                     "The field results[0].resultEndTime must be a time."],
            "Podcast transcript" => [new PodcastTranscriptParser(), '{"segments": [{"startTime": -5, "endTime": 1, "body": "Hi"}, {"startTime": 2, "endTime": 3, "body": "Ok"}]}',
                                     "The field segments[0].startTime must be a number."],
            "YouTube json3"      => [new YouTubeTimedTextParser(), '{"events": [{"tStartMs": -5000, "dDurationMs": 1000, "segs": [{"utf8": "Hi"}]}, '
                                     . '{"tStartMs": 2000, "dDurationMs": 1000, "segs": [{"utf8": "Ok"}]}]}',
                                     "The field events[0].tStartMs must be a number."],
        ];
    }


    #[DataProvider("negativeTimes")]
    public function testThrowsForANegativeTime(SubtitleParser $parser, string $json, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        $parser->parse($json, new ReadOptions());
    }


    #[DataProvider("negativeTimes")]
    public function testSkipsTheCueWithANegativeTimeInLenientMode(SubtitleParser $parser, string $json, string $message): void
    {
        $subtitle = $parser->parse($json, new ReadOptions(lenient: true));

        $this->assertCount(1, $subtitle->getCues());
        $this->assertSame([2.0, 3.0, "Ok"], [$subtitle->getCues()[0]->getStart(), $subtitle->getCues()[0]->getEnd(), $subtitle->getCues()[0]->getText()]);
        $this->assertCount(1, $subtitle->getParseWarnings());
        $this->assertSame($message, $subtitle->getParseWarnings()[0]->message);
        $this->assertSame(ParseWarningAction::Skipped, $subtitle->getParseWarnings()[0]->action);
    }


    public function testPodcastChaptersThrowForANegativeTimeAlsoInLenientMode(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The field chapters[1].startTime must be a number.");

        (new PodcastChaptersParser())->parse('{"chapters": [{"startTime": 0, "title": "A"}, {"startTime": -5, "title": "B"}]}', new ReadOptions(lenient: true));
    }
}
