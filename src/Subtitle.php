<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\Container\Matroska\MatroskaTrack;
use SubtitleToolbox\Exceptions\CueNotFoundException;
use SubtitleToolbox\Exceptions\ImageCueWithoutTextException;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\InvalidFormatterException;
use SubtitleToolbox\Exceptions\InvalidParserException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Exceptions\UnknownFormatException;
use SubtitleToolbox\Formatters\ImageFormatter;
use SubtitleToolbox\Formatters\Options\CsvOptions;
use SubtitleToolbox\Formatters\Options\IttOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdOptions;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\OcrEngine;
use SubtitleToolbox\Ocr\OcrRunner;
use SubtitleToolbox\Parsers\IttParser;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\VobSubReadOptions;


class Subtitle implements \IteratorAggregate, \Countable
{
    use Retiming;
    use Validation;
    use CueEditing;
    use Fixes;
    use TextTransforms;
    use CueLookup;
    use ArrayConversion;
    use ShortCueMerging;

    /** @var array|SubtitleCue[] */
    protected $cues;

    public const METADATA_TITLE    = "title";
    public const METADATA_AUTHOR   = "author";
    public const METADATA_ARTIST   = "artist";
    public const METADATA_ALBUM    = "album";
    public const METADATA_LANGUAGE = "language";

    /** @var array<string, string> */
    protected array $metadata = [];

    /** @var list<array{text: string, beforeCueIndex: int}> */
    protected array $comments = [];

    /** @var array<string, array> */
    protected array $formatData = [];

    /** @var list<ParseWarning> */
    protected array $parseWarnings = [];

    protected ?Format $format = null;


    public function __construct()
    {
        $this->cues = [];
    }


    /**
     * Copies the cues too, so edits on the copy leave the original unchanged.
     */
    public function __clone()
    {
        $this->cues           = array_map(fn (SubtitleCue $cue): SubtitleCue => clone $cue, $this->cues);
        $this->cueLookupIndex = null;
    }


    /**
     * Drops the cue lookup cache, because its edit count is only valid in the process that built it.
     */
    public function __wakeup(): void
    {
        $this->cueLookupIndex = null;
    }


    /**
     * Reads the file at $path in $format. For Format::VobSub, $path is the .idx or the .sub file, and the other file
     * must lie next to it. An MKV or WebM file throws, see loadTrack().
     */
    public static function load(string $path, Format $format, ?ReadOptions $options = null): self
    {
        $options ??= new ReadOptions();
        if (self::isMatroskaFile($path)) {
            throw new InvalidParserException("$path is an MKV or WebM file. Call loadTrack() with a track number.");
        }
        if ($format !== Format::VobSub) {
            return self::fromString(self::readFile($path), $format, $options);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $isIdx     = strtolower($extension) === "idx";
        $other     = self::pairedFile($path, $isIdx ? "sub" : "idx");
        [$idxPath, $subPath] = $isIdx ? [$path, $other] : [$other, $path];

        $vobSubOptions = new ReadOptions(
            encoding: $options->encoding,
            lenient: $options->lenient,
            fps: $options->fps,
            wordTimestamps: $options->wordTimestamps,
            speakerVoices: $options->speakerVoices,
            lastCueDuration: $options->lastCueDuration,
            track: $options->track,
            language: $options->language,
            format: new VobSubReadOptions(StringHelpers::convertToUtf8(self::readFile($idxPath), $options->encoding)),
        );

        return self::parseUtf8(self::readFile($subPath), Format::VobSub, $vobSubOptions);
    }


    /**
     * Reads the file at $path in the format that its content shows, else in the format of its extension. It tries only
     * formats whose isAutoDetected() is true. An MKV or WebM file must hold exactly 1 subtitle track.
     *
     * @throws UnknownFormatException when no such format matches.
     */
    public static function loadAutoDetectFormat(string $path, ?ReadOptions $options = null): self
    {
        $options ??= new ReadOptions();
        if (self::isMatroskaFile($path)) {
            return self::readOnlyTrack(MatroskaReader::open($path), $options);
        }

        $content     = StringHelpers::convertToUtf8(self::readFile($path), $options->encoding);
        $byExtension = Format::fromPath($path);
        $format      = Format::detect($content);
        // Detection returns TTML for an iTT file. IttParser reads the same cues and keeps the iTT timing.
        if ($format === Format::Ttml && $byExtension === Format::Itt) {
            $format = Format::Itt;
        }
        // An extension that a format without detection also uses, such as .json for Deepgram, says nothing.
        $format ??= $byExtension !== null && self::extensionOnlyOfAutoDetectedFormats($path) && $byExtension->canRead()
            ? $byExtension
            : throw new UnknownFormatException(self::unknownFormatMessage("load()"));

        return $format === Format::VobSub ? self::load($path, $format, $options) : self::parseUtf8($content, $format, $options);
    }


    /**
     * Reads the subtitle track with the TrackNumber $track of an MKV or WebM file. The codec of the track picks the
     * parser. tracks() lists the track numbers.
     */
    public static function loadTrack(string $path, int $track, ?ReadOptions $options = null): self
    {
        return self::readTrack(MatroskaReader::open(self::checkedPath($path)), $track, $options ?? new ReadOptions());
    }


    /**
     * Returns the subtitle tracks of an MKV or WebM file.
     *
     * @return list<MatroskaTrack>
     */
    public static function tracks(string $path): array
    {
        return MatroskaReader::open(self::checkedPath($path))->getSubtitleTracks();
    }


    /**
     * Reads $content in $format. A UTF-16 or UTF-32 BOM, or else ReadOptions::$encoding such as "Windows-1252", sets
     * the encoding to convert from. MKV and WebM content throws, see loadTrack().
     */
    public static function fromString(string $content, Format $format, ?ReadOptions $options = null): self
    {
        if (str_starts_with($content, MatroskaReader::EBML_MAGIC)) {
            throw new InvalidParserException("The content is an MKV or WebM file. Call loadTrack() with a track number.");
        }
        $options ??= new ReadOptions();

        return self::parseUtf8(StringHelpers::convertToUtf8($content, $options->encoding), $format, $options);
    }


    /**
     * Reads $content in the format that Format::detect() finds. It tries only formats whose isAutoDetected() is true.
     * MKV and WebM content must hold exactly 1 subtitle track.
     *
     * @throws UnknownFormatException when no such format matches.
     */
    public static function fromStringAutoDetectFormat(string $content, ?ReadOptions $options = null): self
    {
        $options ??= new ReadOptions();
        if (str_starts_with($content, MatroskaReader::EBML_MAGIC)) {
            $stream = fopen("php://temp", "w+b");
            fwrite($stream, $content);
            rewind($stream);

            return self::readOnlyTrack(MatroskaReader::open($stream), $options);
        }

        $content = StringHelpers::convertToUtf8($content, $options->encoding);
        $format  = Format::detect($content) ?? throw new UnknownFormatException(self::unknownFormatMessage("fromString()"));

        return self::parseUtf8($content, $format, $options);
    }


    /**
     * Returns the format that load(), loadAutoDetectFormat(), loadTrack() or a fromString call read, or null for a
     * subtitle from new Subtitle() or fromArray(). For an MKV track, it is the format of the track codec.
     */
    public function getFormat(): ?Format
    {
        return $this->format;
    }


    private static function parseUtf8(string $content, Format $format, ReadOptions $options): self
    {
        $parserClass = FormatRegistry::parserClass($format)
            ?? throw new InvalidParserException("The format {$format->value} can be written but not read.");

        $subtitle         = (new $parserClass())->parse($content, $options);
        $subtitle->format = $format;

        return $subtitle;
    }


    private static function readTrack(MatroskaReader $reader, int $track, ReadOptions $options): self
    {
        $subtitle         = $reader->extract($track, $options);
        $subtitle->format = $reader->trackFormat($track);

        return $subtitle;
    }


    private static function readOnlyTrack(MatroskaReader $reader, ReadOptions $options): self
    {
        $tracks = $reader->getSubtitleTracks();
        if (count($tracks) !== 1) {
            throw new InvalidParserException($tracks === [] ? "The MKV or WebM file has no subtitle track." :
                "The MKV or WebM file has " . count($tracks) . " subtitle tracks. Call loadTrack() with one of them:\n" .
                implode("\n", array_map(fn (MatroskaTrack $track): string => "  $track->number: " . $track->describe(), $tracks)));
        }

        return self::readTrack($reader, $tracks[0]->number, $options);
    }


    private static function unknownFormatMessage(string $call): string
    {
        return "Format detection found no subtitle format. Call $call with a format. " .
                                          "Chapters and cloud speech-to-text JSON always need one, for example Format::Deepgram.";
    }


    private static function extensionOnlyOfAutoDetectedFormats(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        foreach (Format::cases() as $format) {
            if (!$format->isAutoDetected() && in_array($extension, $format->extensions(), true)) {
                return false;
            }
        }

        return true;
    }


    private static function pairedFile(string $path, string $extension): string
    {
        $own  = pathinfo($path, PATHINFO_EXTENSION);
        $stem = match (true) {
            $own !== ""                => substr($path, 0, -strlen($own)),
            str_ends_with($path, ".") => $path,
            default                    => "$path.",
        };
        foreach ([$extension, strtoupper($extension)] as $candidate) {
            if (is_file($stem . $candidate)) {
                return $stem . $candidate;
            }
        }

        throw new InvalidArgumentException("VobSub needs the .$extension file next to $path, but $stem$extension does not exist.");
    }


    private static function checkedPath(string $path): string
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("The file $path does not exist.");
        }

        return $path;
    }


    private static function readFile(string $path, ?int $length = null): string
    {
        $content = @file_get_contents(self::checkedPath($path), false, null, 0, $length);
        if ($content === false) {
            throw new InvalidArgumentException("Cannot read the file $path.");
        }

        return $content;
    }


    private static function isMatroskaFile(string $path): bool
    {
        return self::readFile($path, strlen(MatroskaReader::EBML_MAGIC)) === MatroskaReader::EBML_MAGIC;
    }


    /**
     * Returns what the parser skipped or repaired in lenient mode. A subtitle that no parser read has none.
     *
     * @return list<ParseWarning>
     */
    public function getParseWarnings(): array
    {
        return $this->parseWarnings;
    }


    /**
     * @internal SubtitleParser::parse() sets the warnings of its read.
     *
     * @param list<ParseWarning> $warnings
     */
    public function setParseWarnings(array $warnings): self
    {
        $this->parseWarnings = $warnings;

        return $this;
    }


    /**
     * Writes the subtitle to $path in $format, else in the format of the extension of $path. See toString().
     */
    public function save(string $path, ?Format $format = null, ?WriteOptions $options = null): void
    {
        $format ??= Format::fromPath($path)
            ?? throw new InvalidFormatterException("The extension of $path names no format. Pass a format to save().");
        $content  = $this->toString($format, $options ?? new WriteOptions());

        if (@file_put_contents($path, $content) === false) {
            throw new InvalidArgumentException("Cannot write the file $path.");
        }
    }


    /**
     * Writes the subtitle in $format and throws on an image cue without text, unless the format writes images.
     * MicroDVD and iTT take the frame rate from the options, else from the format data of their parser. TSV writes
     * tabs and CSV from a TSV load writes commas, unless CsvOptions::$delimiter is set.
     */
    public function toString(Format $format, WriteOptions $options = new WriteOptions()): string
    {
        $options = $this->withFormatDefaults($format, $options);
        $formatterClass = FormatRegistry::formatterClass($format)
            ?? throw new InvalidFormatterException("The format {$format->value} can be read but not written.");

        $subtitle = $this;
        if (!is_subclass_of($formatterClass, ImageFormatter::class)) {
            $imageCueIndexes = array_keys(array_filter($this->cues, fn (SubtitleCue $cue): bool =>
                CueImage::isImageCue($cue) && $cue->getLines() === []));

            if ($imageCueIndexes !== [] && !$options->skipImageCues) {
                throw new ImageCueWithoutTextException("Cue #{$imageCueIndexes[0]} holds an image but no text. " .
                                                       "Run recognizeText() first, or pass WriteOptions(skipImageCues: true).");
            }
            if ($imageCueIndexes !== []) {
                $subtitle = $this->withoutCues($imageCueIndexes);
            }
        }

        return (new $formatterClass())->format($subtitle, $options);
    }


    private function withFormatDefaults(Format $format, WriteOptions $options): WriteOptions
    {
        $formatOptions = $options->format;
        $delimiter = match (true) {
            $format === Format::Tsv                                   => "\t",
            $format === Format::Csv && $this->format === Format::Tsv => ",",
            default                                                   => null,
        };
        $csv = $formatOptions ?? new CsvOptions();
        if ($delimiter !== null && $csv instanceof CsvOptions && $csv->delimiter === null) {
            $formatOptions = new CsvOptions($delimiter, $csv->timeFormat, $csv->frameRate, $csv->secondText,
                                            $csv->secondTextHeader, $csv->escapeFormulas);
        }
        if ($format === Format::MicroDvd && $formatOptions === null) {
            $formatOptions = new MicroDvdOptions($this->getFormatData(MicroDvdParser::FORMAT_DATA_KEY)["frameRate"]
                ?? throw new InvalidArgumentException("MicroDVD output needs the frame rate of the video. Pass MicroDvdOptions::frameRate."));
        }
        if ($format === Format::Itt && ($formatOptions === null || ($formatOptions instanceof IttOptions && $formatOptions->frameRate === null))
            && !isset($this->getFormatData(IttParser::FORMAT)["frameRate"])) {
            throw new InvalidArgumentException("iTT output needs the frame rate of the video. Pass IttOptions::frameRate.");
        }

        return $formatOptions === $options->format ? $options : new WriteOptions(
            $options->lineEnding,
            $options->bom,
            $options->stripTags,
            $options->skipImageCues,
            $formatOptions,
        );
    }


    /**
     * @return array|SubtitleCue[]
     */
    public function getCues(): array
    {
        return $this->cues;
    }


    public function addCue(SubtitleCue $cue, bool $reIndexAfterAdding = true): self
    {
        $this->cues[] = $cue;

        if ($reIndexAfterAdding) {
            $this->reIndexCues();
        }

        return $this;
    }


    public function removeCue(int $cueIndex, bool $reIndexAfterRemoval = true): self
    {
        if (!array_key_exists($cueIndex, $this->cues)) {
            throw new CueNotFoundException("Cannot remove cue $cueIndex - cue not found!");
        }

        unset($this->cues[$cueIndex]);

        if ($reIndexAfterRemoval) {
            $this->reIndexCues();
        }

        return $this;
    }


    public function reIndexCues(): self
    {
        $commentCues = array_map(
            fn (array $comment): ?SubtitleCue => $this->findCueAtOrAfter($comment["beforeCueIndex"]),
            $this->comments
        );

        usort($this->cues, fn (SubtitleCue $cue1, SubtitleCue $cue2): int => $cue1->getStart() <=> $cue2->getStart());

        foreach ($commentCues as $commentIndex => $cue) {
            $cueIndex = $cue === null ? false : array_search($cue, $this->cues, true);

            $this->comments[$commentIndex]["beforeCueIndex"] = $cueIndex === false ? count($this->cues) : $cueIndex;
        }
        $this->sortComments();

        return $this;
    }


    public function getMetadata(string $key): ?string
    {
        return $this->metadata[$key] ?? null;
    }


    /**
     * Sets one metadata value, or removes the key when the value is null.
     */
    public function setMetadata(string $key, ?string $value): self
    {
        if ($value === null) {
            unset($this->metadata[$key]);
        } else {
            $this->metadata[$key] = $value;
        }

        return $this;
    }


    /**
     * @return array<string, string>
     */
    public function getAllMetadata(): array
    {
        return $this->metadata;
    }


    /**
     * @return list<array{text: string, beforeCueIndex: int}>
     */
    public function getComments(): array
    {
        return $this->comments;
    }


    /**
     * Adds a comment that a formatter writes before the cue at the given index, or after the last cue.
     */
    public function addComment(string $text, int $beforeCueIndex): self
    {
        if ($beforeCueIndex < 0) {
            throw new InvalidArgumentException("Cannot add a comment before cue $beforeCueIndex - " .
                                                "the cue index must not be negative!");
        }

        $this->comments[] = ["text" => $text, "beforeCueIndex" => $beforeCueIndex];
        $this->sortComments();

        return $this;
    }


    /**
     * Returns the data that only the given format reads, or an empty array.
     */
    public function getFormatData(string $format): array
    {
        return $this->formatData[$format] ?? [];
    }


    public function setFormatData(string $format, array $data): self
    {
        if ($data === []) {
            unset($this->formatData[$format]);
        } else {
            $this->formatData[$format] = $data;
        }

        return $this;
    }


    private function findCueAtOrAfter(int $cueIndex): ?SubtitleCue
    {
        foreach ($this->cues as $index => $cue) {
            if ($index >= $cueIndex) {
                return $cue;
            }
        }

        return null;
    }


    private function sortComments(): void
    {
        usort($this->comments, fn (array $comment1, array $comment2): int =>
            $comment1["beforeCueIndex"] <=> $comment2["beforeCueIndex"]);
    }


    /**
     * Sets the lines of every image cue without text to the text that $engine reads, see OcrRunner.
     */
    public function recognizeText(OcrEngine $engine, ?string $language = null): self
    {
        (new OcrRunner($engine))->run($this, $language);

        return $this;
    }


    /**
     * @param list<int> $cueIndexes
     */
    private function withoutCues(array $cueIndexes): self
    {
        $copy = clone $this;
        $kept = array_diff_key($this->cues, array_flip($cueIndexes));

        $copy->cues = array_values($kept);
        foreach ($copy->comments as $commentIndex => $comment) {
            $copy->comments[$commentIndex]["beforeCueIndex"] = count(array_filter(
                array_keys($kept),
                fn (int $cueIndex): bool => $cueIndex < $comment["beforeCueIndex"]
            ));
        }

        return $copy;
    }

}
