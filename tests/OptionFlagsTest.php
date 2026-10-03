<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\DeepgramParser;
use SubtitleToolbox\Parsers\PodcastTranscriptParser;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;

class OptionFlagsTest extends TestCase
{
    private const FILES = __DIR__ . "/files/";


    public static function parserFlags(): array
    {
        return [
            "Whisper word timestamps" => [WhisperJsonParser::class, WhisperJsonParser::OPTION_WORD_TIMESTAMPS, "whisper/real/openai_whisper_word_timestamps.json"],
            "Whisper speaker voices"  => [WhisperJsonParser::class, WhisperJsonParser::OPTION_SPEAKER_VOICES, "whisper/real/whisperx_diarize.json"],
            "Deepgram speaker voices" => [DeepgramParser::class, DeepgramParser::OPTION_SPEAKER_VOICES, "deepgram/real/pool_utterances_diarize.json"],
            "YouTube word timestamps" => [YouTubeTimedTextParser::class, YouTubeTimedTextParser::OPTION_WORD_TIMESTAMPS, "youtube/real/auto.en.json3"],
            "podcast keep segments"   => [PodcastTranscriptParser::class, PodcastTranscriptParser::OPTION_KEEP_SEGMENTS, "podcast/real/spec_word_segments.json"],
            "podcast word timestamps" => [PodcastTranscriptParser::class, PodcastTranscriptParser::OPTION_WORD_TIMESTAMPS, "podcast/real/spec_word_segments.json"],
        ];
    }


    #[DataProvider("parserFlags")]
    public function testParserFlagWorksAsAListValue(string $parser, string $flag, string $file): void
    {
        $content = file_get_contents(self::FILES . $file);
        $key     = (new $parser([$flag => true]))->parse($content)->toArray();

        $this->assertNotSame((new $parser())->parse($content)->toArray(), $key);
        $this->assertSame($key, (new $parser([$flag]))->parse($content)->toArray());
    }
}
