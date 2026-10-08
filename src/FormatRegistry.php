<?php

declare(strict_types=1);

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
final class FormatRegistry
{
    /**
     * Format value => parser class, formatter class and file extensions. Null means that the library cannot read or
     * write the format. The first extension is the one for new files. When two formats list an extension, the
     * earlier format owns it, so `.sub` is MicroDVD, `.json` is the library JSON and `.txt` is plain text.
     */
    private const FORMATS = [
        Format::Ass->value                => [AssParser::class, AssFormatter::class, ["ass", "ssa"]],
        Format::Csv->value                => [CsvParser::class, CsvFormatter::class, ["csv"]],
        Format::FfMetadataChapters->value => [FfMetadataChaptersParser::class, FfMetadataChaptersFormatter::class, ["ffmeta"]],
        Format::HtmlTranscript->value     => [HtmlTranscriptParser::class, HtmlTranscriptFormatter::class, ["html", "htm"]],
        Format::Itt->value                => [IttParser::class, IttFormatter::class, ["itt"]],
        Format::Json->value               => [JsonParser::class, JsonFormatter::class, ["json"]],
        Format::AssemblyAi->value         => [AssemblyAiParser::class, null, ["json"]],
        Format::AwsTranscribe->value      => [AwsTranscribeParser::class, null, ["json"]],
        Format::Deepgram->value           => [DeepgramParser::class, null, ["json"]],
        Format::GoogleSpeech->value       => [GoogleSpeechParser::class, null, ["json"]],
        Format::Lyrics->value             => [LyricsParser::class, LyricsFormatter::class, ["lrc"]],
        Format::MicroDvd->value           => [MicroDvdParser::class, MicroDvdFormatter::class, ["sub"]],
        Format::MpSub->value              => [MpSubParser::class, MpSubFormatter::class, ["mpsub"]],
        Format::Pgs->value                => [PgsParser::class, PgsFormatter::class, ["sup"]],
        Format::Sami->value               => [SamiParser::class, SamiFormatter::class, ["smi", "sami"]],
        Format::Sbv->value                => [SbvParser::class, SbvFormatter::class, ["sbv"]],
        Format::Scc->value                => [SccParser::class, SccFormatter::class, ["scc"]],
        Format::SubRip->value             => [SubRipParser::class, SubRipFormatter::class, ["srt"]],
        Format::EbuStl->value             => [EbuStlParser::class, EbuStlFormatter::class, ["stl"]],
        Format::SubViewer->value          => [SubViewerParser::class, SubViewerFormatter::class, ["sub"]],
        Format::Ttml->value               => [TtmlParser::class, TtmlFormatter::class, ["ttml", "dfxp", "xml"]],
        Format::Tsv->value                => [CsvParser::class, CsvFormatter::class, ["tsv"]],
        Format::PlainText->value          => [null, PlainTextFormatter::class, ["txt"]],
        Format::Mpl2->value               => [Mpl2Parser::class, Mpl2Formatter::class, ["txt"]],
        Format::TmPlayer->value           => [TmPlayerParser::class, TmPlayerFormatter::class, ["txt"]],
        Format::VobSub->value             => [VobSubParser::class, null, ["idx"]],
        Format::WebVtt->value             => [WebVttParser::class, WebVttFormatter::class, ["vtt"]],
        Format::Whisper->value            => [WhisperJsonParser::class, null, ["json"]],
        Format::YouTubeTimedText->value   => [YouTubeTimedTextParser::class, null, ["json3", "srv3", "srv1"]],
        Format::OgmChapters->value        => [OgmChaptersParser::class, OgmChaptersFormatter::class, ["txt"]],
        Format::PodcastChapters->value    => [PodcastChaptersParser::class, PodcastChaptersFormatter::class, ["json"]],
        Format::PodcastTranscript->value  => [PodcastTranscriptParser::class, PodcastTranscriptFormatter::class, ["json"]],
        Format::YouTubeChapters->value    => [YouTubeChaptersParser::class, YouTubeChaptersFormatter::class, ["txt"]],
    ];


    private static function forExtension(string $extension): ?Format
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
     * Returns the FormatReadOptions class that the parser of $format reads, or null for none.
     *
     * @return class-string<Parsers\Options\FormatReadOptions>|null
     */
    public static function readOptionsClass(Format $format): ?string
    {
        $parser = self::parserClass($format);

        return $parser === null ? null : (new \ReflectionClassConstant($parser, "FORMAT_OPTIONS"))->getValue();
    }


    /**
     * @return list<string>
     */
    public static function extensions(Format $format): array
    {
        return self::FORMATS[$format->value][2];
    }
}
