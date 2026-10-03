<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * @internal Use Format::detect().
 */
class FormatDetector
{
    private const LRC_TIMESTAMP = '\[\d{2,3}:\d{2}(?:[.:]\d{2,3})?\]';

    private const SUBVIEWER_TIMING = '\d{2}:\d{2}:\d{2}\.\d{2},\d{2}:\d{2}:\d{2}\.\d{2}';

    private const XML_PROLOG = '(?:\s|<\?.*?\?>|<!--.*?-->|<!DOCTYPE[^>]*>)*';

    /**
     * The signatures of the text and binary formats in check order, keyed by format. Each pattern runs on the content
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
     * 12. EBU STL: a 3-digit code page, then the disk format code STL25.01 or STL30.01.
     * 13. SCC: the `Scenarist_SCC V1.0` header line.
     * 14. YouTube srv1 and srv3: a `<timedtext>` or `<transcript>` root after an optional XML declaration.
     * 15. MPL2: a `[start][end]` first line in tenths of a second. No earlier signature matches it: LRC needs a colon
     *     inside the brackets, and MicroDVD needs braces.
     * 16. TMPlayer: a first line such as `00:00:01:`, `0:00:01=` or `00:00:01,1=`. SBV and SubViewer 2 need a dot after the seconds.
     * 17. Podcasting 2.0 HTML: a tag at the start, and a `<cite>` and a `<time>` element. It comes last, because TTML, SAMI
     *     and the YouTube XML formats can hold such elements.
     */
    private const SIGNATURES = [
        Format::WebVtt->value   => '/\AWEBVTT(?:[ \t\n]|\z)/',
        Format::Ttml->value     => '/\A' . self::XML_PROLOG . '<(?:[A-Za-z_][\w.-]*:)?tt[\s>\/]/s',
        Format::Sami->value     => '/\A' . self::XML_PROLOG . '<SAMI[\s>]/is',
        Format::Ass->value      => '/\A\[Script Info\][ \t]*$/im',
        Format::MpSub->value    => '/\A(?=[A-Z]+=).*?^FORMAT=/ms',
        Format::MicroDvd->value => '/\A\{\d+\}\{\d*\}/',
        Format::SubRip->value   => '/\A\d+[ \t]*\n[ \t]*\d+:\d{2}:\d{2}(?:[,.]\d+)?[ \t]*-->/',
        Format::Sbv->value      => '/\A\d+:\d{2}:\d{2}\.\d{3},\d+:\d{2}:\d{2}\.\d{3}[ \t]*$/m',
        Format::SubViewer->value => '/^\*{8} START SCRIPT \*{8}[ \t]*$' .
                                    '|\A(?:\[INFORMATION\]|(?:\[.*\n)*' . self::SUBVIEWER_TIMING . ')[ \t]*$/m',
        Format::Lyrics->value   => '/\A(?=' . self::LRC_TIMESTAMP . '|\[[A-Za-z#][A-Za-z0-9_]*:[^\]\n]*\]).*?^[ \t]*' .
                                   self::LRC_TIMESTAMP . '/ms',
        Format::Pgs->value      => '/\APG.{8}[\x14-\x17\x80]/s',
        Format::EbuStl->value   => '/\A\d{3}STL(?:25|30)\.01/',
        Format::Scc->value      => '/\AScenarist_SCC V1\.0[ \t]*$/m',
        Format::YouTube->value  => '/\A' . self::XML_PROLOG . '<(?:timedtext|transcript)[\s>\/]/s',
        Format::Mpl2->value     => '/\A\[\d+\]\[\d+\]/',
        Format::TmPlayer->value => '/\A\d+:[0-5]\d:[0-5]\d(?:,\d+)?[:=]/',
        Format::HtmlTranscript->value => '/\A' . self::XML_PROLOG . '(?=<)(?=.*?<cite[\s>])(?=.*?<time[\s>])/is',
    ];


    /**
     * Returns the subtitle format of $content, or null when no format matches. It never returns chapters or cloud
     * speech JSON. Content that starts with `{` and is a JSON object goes to the JSON key checks only.
     */
    public static function detect(string $content): ?Format
    {
        $content = ltrim(StringHelpers::removeUtf8Bom($content));

        if (str_starts_with($content, "{")) {
            $data = json_decode($content, false, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if ($data instanceof \stdClass) {
                return self::detectJson($data);
            }
        }

        $content = StringHelpers::normalizeEOLs($content);
        foreach (self::SIGNATURES as $format => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return Format::from($format);
            }
        }

        return null;
    }


    // Podcasting 2.0 JSON comes before Whisper JSON, which also has a "segments" list.
    private static function detectJson(\stdClass $data): ?Format
    {
        $version  = $data->version ?? null;
        $segments = $data->segments ?? null;
        $events   = $data->events ?? null;

        return match (true) {
            (is_int($version) || is_float($version)) && is_array($data->cues ?? null) => Format::Json,
            self::firstItemHas($segments, "startTime", "body")                         => Format::PodcastTranscript,
            is_array($segments) || is_array($data->transcription ?? null)              => Format::Whisper,
            self::firstItemHas($events, "tStartMs")                                    => Format::YouTube,
            default                                                                    => null,
        };
    }


    private static function firstItemHas(mixed $list, string ...$keys): bool
    {
        $first = is_array($list) ? ($list[0] ?? null) : null;
        if (!$first instanceof \stdClass) {
            return false;
        }
        foreach ($keys as $key) {
            if (!property_exists($first, $key)) {
                return false;
            }
        }

        return true;
    }
}
