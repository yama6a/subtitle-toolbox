<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Formatters\AssFormatter;
use SubtitleToolbox\Formatters\CsvFormatter;
use SubtitleToolbox\Formatters\EbuStlFormatter;
use SubtitleToolbox\Formatters\IttFormatter;
use SubtitleToolbox\Formatters\JsonFormatter;
use SubtitleToolbox\Formatters\LyricsFormatter;
use SubtitleToolbox\Formatters\MicroDvdFormatter;
use SubtitleToolbox\Formatters\Mpl2Formatter;
use SubtitleToolbox\Formatters\MpSubFormatter;
use SubtitleToolbox\Formatters\PgsFormatter;
use SubtitleToolbox\Formatters\PlainTextFormatter;
use SubtitleToolbox\Formatters\SamiFormatter;
use SubtitleToolbox\Formatters\SbvFormatter;
use SubtitleToolbox\Formatters\SccFormatter;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\SubViewerFormatter;
use SubtitleToolbox\Formatters\TmPlayerFormatter;
use SubtitleToolbox\Formatters\TtmlFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Parsers\EbuStlParser;
use SubtitleToolbox\Parsers\IttParser;
use SubtitleToolbox\Parsers\JsonParser;
use SubtitleToolbox\Parsers\LyricsParser;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\Mpl2Parser;
use SubtitleToolbox\Parsers\MpSubParser;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\SamiParser;
use SubtitleToolbox\Parsers\SbvParser;
use SubtitleToolbox\Parsers\SccParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\SubViewerParser;
use SubtitleToolbox\Parsers\TmPlayerParser;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Parsers\VobSubParser;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;

class FormatRegistry
{
    /**
     * Format name => parser class, formatter class and file extensions. Null means that the library cannot read or
     * write the format. The first extension is the one for new files. When two formats list an extension, the
     * earlier format owns it, so `.sub` is MicroDVD, `.json` is the library JSON and `.txt` is plain text.
     */
    private const FORMATS = [
        "ass"       => [AssParser::class, AssFormatter::class, ["ass", "ssa"]],
        "csv"       => [CsvParser::class, CsvFormatter::class, ["csv"]],
        "itt"       => [IttParser::class, IttFormatter::class, ["itt"]],
        "json"      => [JsonParser::class, JsonFormatter::class, ["json"]],
        "lrc"       => [LyricsParser::class, LyricsFormatter::class, ["lrc"]],
        "microdvd"  => [MicroDvdParser::class, MicroDvdFormatter::class, ["sub"]],
        "mpsub"     => [MpSubParser::class, MpSubFormatter::class, ["mpsub"]],
        "pgs"       => [PgsParser::class, PgsFormatter::class, ["sup"]],
        "sami"      => [SamiParser::class, SamiFormatter::class, ["smi", "sami"]],
        "sbv"       => [SbvParser::class, SbvFormatter::class, ["sbv"]],
        "scc"       => [SccParser::class, SccFormatter::class, ["scc"]],
        "srt"       => [SubRipParser::class, SubRipFormatter::class, ["srt"]],
        "stl"       => [EbuStlParser::class, EbuStlFormatter::class, ["stl"]],
        "subviewer" => [SubViewerParser::class, SubViewerFormatter::class, ["sub"]],
        "ttml"      => [TtmlParser::class, TtmlFormatter::class, ["ttml", "dfxp", "xml"]],
        "tsv"       => [CsvParser::class, CsvFormatter::class, ["tsv"]],
        "txt"       => [null, PlainTextFormatter::class, ["txt"]],
        "mpl2"      => [Mpl2Parser::class, Mpl2Formatter::class, ["txt"]],
        "tmplayer"  => [TmPlayerParser::class, TmPlayerFormatter::class, ["txt"]],
        "vobsub"    => [VobSubParser::class, null, ["idx"]],
        "vtt"       => [WebVttParser::class, WebVttFormatter::class, ["vtt"]],
        "whisper"   => [WhisperJsonParser::class, null, ["json"]],
        "youtube"   => [YouTubeTimedTextParser::class, null, ["json3", "srv3", "srv1"]],
    ];


    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::FORMATS);
    }


    /**
     * Returns the format name for a name or a file extension such as "SRT" or ".ssa", or null for an unknown one.
     */
    public static function find(string $nameOrExtension): ?string
    {
        $key = strtolower(ltrim($nameOrExtension, "."));

        return isset(self::FORMATS[$key]) ? $key : self::forExtension($key);
    }


    public static function forExtension(string $extension): ?string
    {
        $extension = strtolower(ltrim($extension, "."));
        foreach (self::FORMATS as $name => [, , $extensions]) {
            if (in_array($extension, $extensions, true)) {
                return $name;
            }
        }

        return null;
    }


    public static function forPath(string $path): ?string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension === "" ? null : self::forExtension($extension);
    }


    public static function forParser(string $parserClass): ?string
    {
        foreach (self::FORMATS as $name => [$parser]) {
            if ($parser === $parserClass) {
                return $name;
            }
        }

        return null;
    }


    /**
     * @return class-string<Parsers\SubtitleParser>|null
     */
    public static function parserClass(string $name): ?string
    {
        return self::FORMATS[$name][0] ?? null;
    }


    /**
     * @return class-string<Formatters\SubtitleFormatter>|null
     */
    public static function formatterClass(string $name): ?string
    {
        return self::FORMATS[$name][1] ?? null;
    }


    /**
     * @return list<string>
     */
    public static function extensions(string $name): array
    {
        return self::FORMATS[$name][2] ?? [];
    }


    /**
     * @return list<class-string<Parsers\SubtitleParser>>
     */
    public static function parserClasses(): array
    {
        return array_values(array_filter(array_column(self::FORMATS, 0)));
    }


    /**
     * @return list<class-string<Formatters\SubtitleFormatter>>
     */
    public static function formatterClasses(): array
    {
        return array_values(array_filter(array_column(self::FORMATS, 1)));
    }
}
