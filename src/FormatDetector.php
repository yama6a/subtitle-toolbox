<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Parsers\AssemblyAiParser;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\AwsTranscribeParser;
use SubtitleToolbox\Parsers\DeepgramParser;
use SubtitleToolbox\Parsers\EbuStlParser;
use SubtitleToolbox\Parsers\FfMetadataChaptersParser;
use SubtitleToolbox\Parsers\GoogleSpeechParser;
use SubtitleToolbox\Parsers\HtmlTranscriptParser;
use SubtitleToolbox\Parsers\JsonParser;
use SubtitleToolbox\Parsers\LyricsParser;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\Mpl2Parser;
use SubtitleToolbox\Parsers\MpSubParser;
use SubtitleToolbox\Parsers\OgmChaptersParser;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\PodcastChaptersParser;
use SubtitleToolbox\Parsers\PodcastTranscriptParser;
use SubtitleToolbox\Parsers\SamiParser;
use SubtitleToolbox\Parsers\SbvParser;
use SubtitleToolbox\Parsers\SccParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\SubViewerParser;
use SubtitleToolbox\Parsers\TmPlayerParser;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;

class FormatDetector
{
    private const LRC_TIMESTAMP = '\[\d{2,3}:\d{2}(?:[.:]\d{2,3})?\]';

    private const SUBVIEWER_TIMING = '\d{2}:\d{2}:\d{2}\.\d{2},\d{2}:\d{2}:\d{2}\.\d{2}';

    private const XML_PROLOG = '(?:\s|<\?.*?\?>|<!--.*?-->|<!DOCTYPE[^>]*>)*';

    /**
     * The signatures in check order, keyed by parser class. Each pattern runs on the content
     * without a UTF-8 BOM, with LF line endings and without leading white space.
     *
     * 1. WebVTT: the WEBVTT keyword.
     * 2. TTML: a `<tt>` root after an optional XML declaration, comments and DOCTYPE.
     * 3. SAMI: a `<SAMI>` root.
     * 4. ASS and SSA: a `[Script Info]` line. It comes before LRC, which also starts with `[`.
     * 5. MPSub: a `KEY=` first line and a `FORMAT=` header line.
     * 6. MicroDVD: a `{start}{end}` first line.
     * 7. SubRip: a cue number, then a timing line with `-->`. It comes after WebVTT, because
     *    a WebVTT file without its header has the same shape.
     * 8. SBV: two timestamps and a comma between them. Three millisecond digits keep out SubViewer, which has two.
     * 9. SubViewer: a `******** START SCRIPT ********` line for version 1. For version 2, an `[INFORMATION]` first line,
     *    or a timing line with two digits after the dot that only header tags precede. It comes after SBV, and before
     *    LRC, whose signature also matches `[00:00:01]`.
     * 10. LRC: an ID tag or a timestamp in brackets, and at least one timestamp line.
     * 11. PGS: the `PG` magic bytes, then a known segment type after the two 4-byte time stamps.
     * 12. JSON: an object with a numeric "version" key and a "cues" list. The possessive loops skip strings without backtracking.
     * 13. EBU STL: a 3-digit code page, then the disk format code STL25.01 or STL30.01.
     * 14. SCC: the `Scenarist_SCC V1.0` header line.
     * 15. Amazon Transcribe: an object with a "transcripts" list.
     * 16. Deepgram: an object with a "channels" list of objects, and an "alternatives" key after it. It comes after Amazon
     *     Transcribe, whose "channel_labels" object can hold such a list.
     * 17. AssemblyAI: an object with an "audio_url" key, or a "words" list whose first word starts with a "text" key.
     * 18. Google Cloud Speech-to-Text: an object with a "results" list of objects, and an "alternatives" list after it.
     * 19. Podcasting 2.0 JSON: an object with a "segments" list whose segments have a "startTime" and a "body" key. It
     *     comes after JSON, whose format data can hold such a list, and before Whisper JSON, which also has a "segments" list.
     * 20. Whisper JSON: an object with a "segments" or "transcription" list. It comes after JSON, whose format data can hold such
     *     a key, and after Amazon Transcribe and Deepgram, whose speaker labels and topics hold a "segments" list.
     * 21. YouTube timed text: a `<timedtext>` or `<transcript>` root after an optional XML declaration, or an object with an
     *     "events" list whose events have a "tStartMs" key. It comes after JSON and Whisper JSON, which can hold such a list.
     * 22. MPL2: a `[start][end]` first line in tenths of a second. No earlier signature matches it: LRC needs a colon
     *     inside the brackets, and MicroDVD needs braces.
     * 23. TMPlayer: a first line such as `00:00:01:`, `0:00:01=` or `00:00:01,1=`. SBV and SubViewer 2 need a dot after the seconds.
     * 24. Podcasting 2.0 JSON chapters: an object with a "version" key and a "chapters" list. It comes after the other JSON
     *     formats, whose format data can hold such keys.
     * 25. FFmpeg metadata: the `;FFMETADATA` header.
     * 26. OGM chapters: a `CHAPTER01=` line with a time, then a `CHAPTER01NAME=` line, as mkvmerge probes them.
     * 27. Podcasting 2.0 HTML: a tag at the start, and a `<cite>` and a `<time>` element. It comes last, because TTML, SAMI
     *     and the YouTube XML formats can hold such elements.
     */
    private const SIGNATURES = [
        WebVttParser::class   => '/\AWEBVTT(?:[ \t\n]|\z)/',
        TtmlParser::class     => '/\A' . self::XML_PROLOG . '<(?:[A-Za-z_][\w.-]*:)?tt[\s>\/]/s',
        SamiParser::class     => '/\A' . self::XML_PROLOG . '<SAMI[\s>]/is',
        AssParser::class      => '/\A\[Script Info\][ \t]*$/im',
        MpSubParser::class    => '/\A(?=[A-Z]+=).*?^FORMAT=/ms',
        MicroDvdParser::class => '/\A\{\d+\}\{\d*\}/',
        SubRipParser::class   => '/\A\d+[ \t]*\n[ \t]*\d+:\d{2}:\d{2}(?:[,.]\d+)?[ \t]*-->/',
        SbvParser::class      => '/\A\d+:\d{2}:\d{2}\.\d{3},\d+:\d{2}:\d{2}\.\d{3}[ \t]*$/m',
        SubViewerParser::class => '/^\*{8} START SCRIPT \*{8}[ \t]*$' .
                                  '|\A(?:\[INFORMATION\]|(?:\[.*\n)*' . self::SUBVIEWER_TIMING . ')[ \t]*$/m',
        LyricsParser::class   => '/\A(?=' . self::LRC_TIMESTAMP . '|\[[A-Za-z#][A-Za-z0-9_]*:[^\]\n]*\]).*?^[ \t]*' .
                                 self::LRC_TIMESTAMP . '/ms',
        PgsParser::class      => '/\APG.{8}[\x14-\x17\x80]/s',
        JsonParser::class     => '/\A\{(?=(?:[^"]++|"(?!version"\s*+:))*+"version"\s*+:\s*+\d)(?=(?:[^"]++|"(?!cues"\s*+:))*+"cues"\s*+:\s*+\[)/',
        EbuStlParser::class   => '/\A\d{3}STL(?:25|30)\.01/',
        SccParser::class      => '/\AScenarist_SCC V1\.0[ \t]*$/m',
        AwsTranscribeParser::class => '/\A\{(?=(?:[^"]++|"(?!transcripts"\s*+:\s*+\[))*+"transcripts"\s*+:\s*+\[)/',
        DeepgramParser::class      => '/\A\{(?=(?:[^"]++|"(?!channels"\s*+:\s*+\[))*+"channels"\s*+:\s*+\[\s*+\{' .
                                      '(?:[^"]++|"(?!alternatives"\s*+:))*+"alternatives"\s*+:)/',
        AssemblyAiParser::class    => '/\A\{(?=(?:[^"]++|"(?!audio_url"\s*+:|words"\s*+:\s*+\[\s*+\{\s*+"text"\s*+:))*+' .
                                      '"(?:audio_url"\s*+:|words"\s*+:\s*+\[\s*+\{\s*+"text"\s*+:))/',
        GoogleSpeechParser::class  => '/\A\{(?=(?:[^"]++|"(?!results"\s*+:\s*+\[\s*+\{))*+"results"\s*+:\s*+\[\s*+\{' .
                                      '(?:[^"]++|"(?!alternatives"\s*+:))*+"alternatives"\s*+:\s*+\[)/',
        PodcastTranscriptParser::class => '/\A\{(?=(?:[^"]++|"(?!segments"\s*+:))*+"segments"\s*+:\s*+\[\s*+\{' .
                                          '(?=(?:[^"]++|"(?!startTime"\s*+:))*+"startTime"\s*+:)(?:[^"]++|"(?!body"\s*+:))*+"body"\s*+:)/',
        WhisperJsonParser::class => '/\A\{(?=(?:[^"]++|"(?!(?:segments|transcription)"\s*+:))*+"(?:segments|transcription)"\s*+:\s*+\[)/',
        YouTubeTimedTextParser::class => '/\A(?:' . self::XML_PROLOG . '<(?:timedtext|transcript)[\s>\/]' .
                                         '|\{(?=(?:[^"]++|"(?!events"\s*+:))*+"events"\s*+:\s*+\[\s*+\{' .
                                         '(?:[^"]++|"(?!tStartMs"\s*+:))*+"tStartMs"\s*+:))/s',
        Mpl2Parser::class     => '/\A\[\d+\]\[\d+\]/',
        TmPlayerParser::class => '/\A\d+:[0-5]\d:[0-5]\d(?:,\d+)?[:=]/',
        PodcastChaptersParser::class => '/\A\{(?=(?:[^"]++|"(?!version"\s*+:))*+"version"\s*+:)(?=(?:[^"]++|"(?!chapters"\s*+:))*+"chapters"\s*+:\s*+\[)/',
        FfMetadataChaptersParser::class => '/\A;FFMETADATA/',
        OgmChaptersParser::class => '/\ACHAPTER\d+[ \t]*=[ \t]*\d+[ \t]*:.*\n\s*CHAPTER\d+NAME[ \t]*=/',
        HtmlTranscriptParser::class => '/\A' . self::XML_PROLOG . '(?=<)(?=.*?<cite[\s>])(?=.*?<time[\s>])/is',
    ];


    /**
     * Returns the parser class whose signature matches the start of $content, or null when none matches.
     */
    public static function detect(string $content): ?string
    {
        $content = StringHelpers::removeUtf8Bom($content);
        $content = StringHelpers::normalizeEOLs($content);
        $content = ltrim($content);

        foreach (self::SIGNATURES as $parserClass => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return $parserClass;
            }
        }

        return null;
    }
}
