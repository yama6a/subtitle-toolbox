<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\JsonParser;
use SubtitleToolbox\Parsers\LyricsParser;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\MpSubParser;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\SamiParser;
use SubtitleToolbox\Parsers\SbvParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Parsers\WebVttParser;

class FormatDetector
{
    private const LRC_TIMESTAMP = '\[\d{2,3}:\d{2}(?:[.:]\d{2,3})?\]';

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
     * 9. LRC: an ID tag or a timestamp in brackets, and at least one timestamp line.
     * 10. PGS: the `PG` magic bytes, then a known segment type after the two 4-byte time stamps.
     * 11. JSON: an object with a numeric "version" key and a "cues" list. The possessive loops skip strings without backtracking.
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
        LyricsParser::class   => '/\A(?=' . self::LRC_TIMESTAMP . '|\[[A-Za-z#][A-Za-z0-9_]*:[^\]\n]*\]).*?^[ \t]*' .
                                 self::LRC_TIMESTAMP . '/ms',
        PgsParser::class      => '/\APG.{8}[\x14-\x17\x80]/s',
        JsonParser::class     => '/\A\{(?=(?:[^"]++|"(?!version"\s*+:))*+"version"\s*+:\s*+\d)(?=(?:[^"]++|"(?!cues"\s*+:))*+"cues"\s*+:\s*+\[)/',
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
