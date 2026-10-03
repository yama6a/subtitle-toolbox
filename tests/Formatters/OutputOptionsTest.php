<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\FormatRegistry;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Formatters\Options\AssOptions;
use SubtitleToolbox\Formatters\Options\CsvOptions;
use SubtitleToolbox\Formatters\Options\EbuStlOptions;
use SubtitleToolbox\Formatters\Options\HtmlTranscriptOptions;
use SubtitleToolbox\Formatters\Options\IttOptions;
use SubtitleToolbox\Formatters\Options\JsonOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdOptions;
use SubtitleToolbox\Formatters\Options\MpSubOptions;
use SubtitleToolbox\Formatters\Options\PlainTextOptions;
use SubtitleToolbox\Formatters\Options\PodcastTranscriptOptions;
use SubtitleToolbox\Formatters\Options\SccOptions;
use SubtitleToolbox\Formatters\Options\SubViewerOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

class OutputOptionsTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/";

    // EBU STL and PGS are binary, so they have no line ending and no BOM.
    private const BINARY = [Format::EbuStl, Format::Pgs];

    private const WRITES_BOM = [Format::Ass, Format::Csv, Format::Lyrics, Format::MpSub, Format::SubRip, Format::Tsv, Format::WebVtt];

    // The options that a format needs, or that give JSON output line breaks.
    private const FORMAT_OPTIONS = [
        "itt"                => [IttOptions::class, ["frameRate" => 25]],
        "json"               => [JsonOptions::class, ["prettyPrint" => true]],
        "microdvd"           => [MicroDvdOptions::class, ["frameRate" => 25]],
        "podcast-transcript" => [PodcastTranscriptOptions::class, ["prettyPrint" => true]],
    ];

    private const OPTIONS_CLASSES = [
        "ass"                => AssOptions::class,
        "csv"                => CsvOptions::class,
        "html"               => HtmlTranscriptOptions::class,
        "itt"                => IttOptions::class,
        "json"               => JsonOptions::class,
        "microdvd"           => MicroDvdOptions::class,
        "mpsub"              => MpSubOptions::class,
        "podcast-transcript" => PodcastTranscriptOptions::class,
        "scc"                => SccOptions::class,
        "stl"                => EbuStlOptions::class,
        "subviewer"          => SubViewerOptions::class,
        "tsv"                => CsvOptions::class,
        "txt"                => PlainTextOptions::class,
    ];


    public static function textFormats(): array
    {
        $formats = [];
        foreach (Format::cases() as $format) {
            if ($format->canWrite() && !in_array($format, self::BINARY, true)) {
                $formats[$format->value] = [$format];
            }
        }

        return $formats;
    }


    public static function writableFormats(): array
    {
        $formats = [];
        foreach (Format::cases() as $format) {
            if ($format->canWrite()) {
                $formats[$format->value] = [$format];
            }
        }

        return $formats;
    }


    #[DataProvider("textFormats")]
    public function testDefaultsKeepLfAndTheBomOfTheFormat(Format $format): void
    {
        $output = self::subtitle($format)->toString($format, self::options($format));

        $this->assertSame(in_array($format, self::WRITES_BOM, true), StringHelpers::hasUtf8Bom($output));
        $this->assertStringNotContainsString("\r", $output);
        $this->assertSame($output, self::subtitle($format)->toString($format, self::options($format, LineEnding::Lf)));
    }


    #[DataProvider("textFormats")]
    public function testCrLfReplacesEveryLineEnding(Format $format): void
    {
        $default = self::subtitle($format)->toString($format, self::options($format));
        $output  = self::subtitle($format)->toString($format, self::options($format, LineEnding::Crlf));

        $this->assertSame(str_replace("\n", "\r\n", $default), $output);
        $this->assertGreaterThan(0, substr_count($output, "\r\n"));
    }


    #[DataProvider("textFormats")]
    public function testBomOptionAddsOrRemovesTheUtf8Bom(Format $format): void
    {
        $default = StringHelpers::removeUtf8Bom(self::subtitle($format)->toString($format, self::options($format)));

        $this->assertSame("\xEF\xBB\xBF" . $default, self::subtitle($format)->toString($format, self::options($format, bom: true)));
        $this->assertSame($default, self::subtitle($format)->toString($format, self::options($format, bom: false)));
    }


    #[DataProvider("textFormats")]
    public function testCrLfAndBomWorkTogether(Format $format): void
    {
        $default = StringHelpers::removeUtf8Bom(self::subtitle($format)->toString($format, self::options($format)));
        $output  = self::subtitle($format)->toString($format, self::options($format, LineEnding::Crlf, true));

        $this->assertSame("\xEF\xBB\xBF" . str_replace("\n", "\r\n", $default), $output);
    }


    #[DataProvider("textFormats")]
    public function testFormatterCalledDirectlyAppliesTheOptions(Format $format): void
    {
        $formatter = FormatRegistry::formatterClass($format);
        $output    = (new $formatter())->format(self::subtitle($format), self::options($format, LineEnding::Crlf, false));

        $this->assertFalse(StringHelpers::hasUtf8Bom($output));
        $this->assertStringContainsString("\r\n", $output);
        $this->assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $output);
    }


    #[DataProvider("writableFormats")]
    public function testOptionsOfAnotherFormatThrow(Format $format): void
    {
        $other = $format === Format::Ass ? new SccOptions() : new AssOptions();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(isset(self::OPTIONS_CLASSES[$format->value]) ? ", got AssOptions." : " takes no format options, got AssOptions.");
        if ($format === Format::Ass) {
            $this->expectExceptionMessage("AssFormatter takes AssOptions, got SccOptions.");
        }

        self::subtitle($format)->toString($format, new WriteOptions(format: $other));
    }


    public function testCsvOptionsForSubRipThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("SubRipFormatter takes no format options, got CsvOptions.");

        self::subtitle(Format::SubRip)->toString(Format::SubRip, new WriteOptions(format: new CsvOptions()));
    }


    #[DataProvider("textFormats")]
    public function testOwnOptionsClassIsAccepted(Format $format): void
    {
        $class = self::OPTIONS_CLASSES[$format->value] ?? null;
        if ($class === null) {
            $this->assertNotSame("", self::subtitle($format)->toString($format, self::options($format)));

            return;
        }

        [, $arguments] = self::FORMAT_OPTIONS[$format->value] ?? [null, []];
        $options       = new WriteOptions(format: new $class(...$arguments));

        $this->assertNotSame("", self::subtitle($format)->toString($format, $options));
    }


    public function testExplicitFrameRateWinsOverTheParsedIttFrameRate(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "itt/real/fcp_23976_styles.itt"), Format::Itt);

        $output = $subtitle->toString(Format::Itt, new WriteOptions(format: new IttOptions(frameRate: 25)));

        $this->assertStringContainsString('ttp:frameRate="25"', $output);
        $this->assertStringContainsString('ttp:frameRateMultiplier="1 1"', $output);
    }


    public function testExplicitFrameRateWinsOverTheParsedEbuStlDiskFormatCode(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "stl/real/harbour_open_30fps.stl"), Format::EbuStl);
        $this->assertSame("STL30.01", substr(file_get_contents(self::FILES . "stl/real/harbour_open_30fps.stl"), 3, 8));

        $output = $subtitle->toString(Format::EbuStl, new WriteOptions(format: new EbuStlOptions(frameRate: 25)));

        $this->assertSame("STL25.01", substr($output, 3, 8));
    }


    public function testExplicitCsvFrameRateAndTimeFormatWinOverTheParsedTable(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "csv/real/excel_de_semicolon.csv"), Format::Csv);
        $first    = array_values($subtitle->getCues())[0];

        $output = $subtitle->toString(Format::Csv, new WriteOptions(format: new CsvOptions(timeFormat: CsvTimeFormat::Frames, frameRate: 25)));
        $start  = sprintf("%02d:%02d:%02d:%02d", ...Timecode::clockSecondsAndFrames($first->getStart(), new FrameRate(25)));

        $this->assertStringContainsString("\n$start;", $output);
    }


    public function testStripTagsWritesTheStrippedSubRipFile(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "srt/strip_xml.srt"), Format::SubRip);

        $this->assertSame(
            file_get_contents(self::FILES . "srt/all_tags_stripped.srt"),
            $subtitle->toString(Format::SubRip, new WriteOptions(stripTags: true))
        );
    }


    public static function stripTagsFormats(): array
    {
        return [
            "ASS"      => [Format::Ass],
            "EBU STL"  => [Format::EbuStl],
            "iTT"      => [Format::Itt],
            "MicroDVD" => [Format::MicroDvd],
            "SAMI"     => [Format::Sami],
            "SubRip"   => [Format::SubRip],
            "TTML"     => [Format::Ttml],
            "WebVTT"   => [Format::WebVtt],
        ];
    }


    #[DataProvider("stripTagsFormats")]
    public function testStripTagsChangesTheOutput(Format $format): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "srt/real/own_styled.srt"), Format::SubRip);
        $format_  = self::FORMAT_OPTIONS[$format->value] ?? null;
        $options  = fn (bool $stripTags): WriteOptions => new WriteOptions(
            stripTags: $stripTags,
            format: $format_ === null ? null : new $format_[0](...$format_[1]),
        );

        $this->assertNotSame($subtitle->toString($format, $options(false)), $subtitle->toString($format, $options(true)));
    }


    public function testOneEbuStlFormatterKeepsNoStateBetweenCalls(): void
    {
        $bakery  = Subtitle::fromString(file_get_contents(self::FILES . "stl/real/bakery_teletext_25fps.stl"), Format::EbuStl);
        $harbour = Subtitle::fromString(file_get_contents(self::FILES . "stl/real/harbour_open_30fps.stl"), Format::EbuStl);

        $formatter = new EbuStlFormatter();
        $first     = $formatter->format($bakery);
        $second    = $formatter->format($harbour);

        $this->assertSame((new EbuStlFormatter())->format($bakery), $first);
        $this->assertSame((new EbuStlFormatter())->format($harbour), $second);
    }


    private static function options(Format $format, LineEnding $lineEnding = LineEnding::Lf, ?bool $bom = null): WriteOptions
    {
        $options = self::FORMAT_OPTIONS[$format->value] ?? null;

        return new WriteOptions(
            lineEnding: $lineEnding,
            bom: $bom,
            format: $options === null ? null : new $options[0](...$options[1]),
        );
    }


    /**
     * CSV keeps a line break inside a cell as LF, as Excel does, so the CSV and TSV cues hold one line each.
     */
    private static function subtitle(Format $format): Subtitle
    {
        $twoLines = in_array($format, [Format::Csv, Format::Tsv], true) ? "<i>Bonjour</i> ça va ?" : "<i>Bonjour</i>\nça va ?";

        return (new Subtitle())
            ->setMetadata(Subtitle::METADATA_TITLE, "Café")
            ->addComment("Note", 1)
            ->addCue(new SubtitleCue(1.5, 4, $twoLines))
            ->addCue(new SubtitleCue(5, 7.25, "Second cue"));
    }
}
