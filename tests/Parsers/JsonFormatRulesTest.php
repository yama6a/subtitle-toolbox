<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
}
