<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Formatters\AssFormatter;
use SubtitleToolbox\Formatters\CsvFormatter;
use SubtitleToolbox\Formatters\EbuStlFormatter;
use SubtitleToolbox\Formatters\FfMetadataChaptersFormatter;
use SubtitleToolbox\Formatters\HtmlTranscriptFormatter;
use SubtitleToolbox\Formatters\IttFormatter;
use SubtitleToolbox\Formatters\JsonFormatter;
use SubtitleToolbox\Formatters\LyricsFormatter;
use SubtitleToolbox\Formatters\MicroDvdFormatter;
use SubtitleToolbox\Formatters\Mpl2Formatter;
use SubtitleToolbox\Formatters\MpSubFormatter;
use SubtitleToolbox\Formatters\OgmChaptersFormatter;
use SubtitleToolbox\Formatters\PgsFormatter;
use SubtitleToolbox\Formatters\PlainTextFormatter;
use SubtitleToolbox\Formatters\PodcastChaptersFormatter;
use SubtitleToolbox\Formatters\PodcastTranscriptFormatter;
use SubtitleToolbox\Formatters\SamiFormatter;
use SubtitleToolbox\Formatters\SbvFormatter;
use SubtitleToolbox\Formatters\SccFormatter;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\SubViewerFormatter;
use SubtitleToolbox\Formatters\TmPlayerFormatter;
use SubtitleToolbox\Formatters\TtmlFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Formatters\YouTubeChaptersFormatter;
use SubtitleToolbox\Parsers\AssemblyAiParser;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\AwsTranscribeParser;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Parsers\DeepgramParser;
use SubtitleToolbox\Parsers\EbuStlParser;
use SubtitleToolbox\Parsers\FfMetadataChaptersParser;
use SubtitleToolbox\Parsers\GoogleSpeechParser;
use SubtitleToolbox\Parsers\HtmlTranscriptParser;
use SubtitleToolbox\Parsers\IttParser;
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
use SubtitleToolbox\Parsers\VobSubParser;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\Parsers\YouTubeChaptersParser;
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;

/**
 * @internal The table behind Format. Use Format in place of it.
 */
class FormatRegistry
{
    /**
     * Format value => parser class, formatter class and file extensions. Null means that the library cannot read or
     * write the format. The first extension is the one for new files. When two formats list an extension, the
     * earlier format owns it, so `.sub` is MicroDVD, `.json` is the library JSON and `.txt` is plain text.
     */
    private const FORMATS = [
        "ass"       => [AssParser::class, AssFormatter::class, ["ass", "ssa"]],
        "csv"       => [CsvParser::class, CsvFormatter::class, ["csv"]],
        "ffmeta"    => [FfMetadataChaptersParser::class, FfMetadataChaptersFormatter::class, ["ffmeta"]],
        "html"      => [HtmlTranscriptParser::class, HtmlTranscriptFormatter::class, ["html", "htm"]],
        "itt"       => [IttParser::class, IttFormatter::class, ["itt"]],
        "json"      => [JsonParser::class, JsonFormatter::class, ["json"]],
        "assemblyai" => [AssemblyAiParser::class, null, ["json"]],
        "aws-transcribe" => [AwsTranscribeParser::class, null, ["json"]],
        "deepgram"  => [DeepgramParser::class, null, ["json"]],
        "google-speech" => [GoogleSpeechParser::class, null, ["json"]],
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
        "ogm"       => [OgmChaptersParser::class, OgmChaptersFormatter::class, ["txt"]],
        "podcast"   => [PodcastChaptersParser::class, PodcastChaptersFormatter::class, ["json"]],
        "podcast-transcript" => [PodcastTranscriptParser::class, PodcastTranscriptFormatter::class, ["json"]],
        "ytchapter" => [YouTubeChaptersParser::class, YouTubeChaptersFormatter::class, ["txt"]],
    ];


    public static function forExtension(string $extension): ?Format
    {
        $extension = strtolower(ltrim($extension, "."));
        foreach (self::FORMATS as $name => [, , $extensions]) {
            if (in_array($extension, $extensions, true)) {
                return Format::from($name);
            }
        }

        return null;
    }


    public static function forPath(string $path): ?Format
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension === "" ? null : self::forExtension($extension);
    }


    /**
     * @return class-string<Parsers\SubtitleParser>|null
     */
    public static function parserClass(Format $format): ?string
    {
        return self::FORMATS[$format->value][0];
    }


    /**
     * @return class-string<Formatters\SubtitleFormatter>|null
     */
    public static function formatterClass(Format $format): ?string
    {
        return self::FORMATS[$format->value][1];
    }


    /**
     * @return list<string>
     */
    public static function extensions(Format $format): array
    {
        return self::FORMATS[$format->value][2];
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
