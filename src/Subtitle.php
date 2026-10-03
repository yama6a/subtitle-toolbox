<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\CueNotFoundException;
use SubtitleToolbox\Exceptions\ImageCueWithoutTextException;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\InvalidFormatterException;
use SubtitleToolbox\Exceptions\InvalidParserException;
use SubtitleToolbox\Formatters\ImageFormatter;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\OcrEngine;
use SubtitleToolbox\Ocr\OcrRunner;


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


    public function __construct()
    {
        $this->cues = [];
    }


    /**
     * Copies the cues too, so edits on the copy leave the original unchanged.
     */
    public function __clone()
    {
        $this->cues = array_map(fn (SubtitleCue $cue): SubtitleCue => clone $cue, $this->cues);
    }


    /**
     * Reads $content in $format. A UTF-16 or UTF-32 BOM, or else ReadOptions::$encoding such as "Windows-1252", sets
     * the encoding to convert from.
     */
    public static function fromString(string $content, Format $format, ?ReadOptions $options = null): self
    {
        $options ??= new ReadOptions();

        return self::parseUtf8(StringHelpers::convertToUtf8($content, $options->encoding), $format, $options);
    }


    /**
     * Reads $content in the format that Format::detect() finds. It tries only formats whose isAutoDetected() is true.
     */
    public static function fromStringAutoDetectFormat(string $content, ?ReadOptions $options = null): self
    {
        $options ??= new ReadOptions();
        $content   = StringHelpers::convertToUtf8($content, $options->encoding);
        $format    = Format::detect($content)
            ?? throw new InvalidParserException("The subtitle format of the content is unknown. Call fromString() with a format.");

        return self::parseUtf8($content, $format, $options);
    }


    private static function parseUtf8(string $content, Format $format, ReadOptions $options): self
    {
        $parserClass = FormatRegistry::parserClass($format)
            ?? throw new InvalidParserException("The format {$format->value} can be written but not read.");

        return (new $parserClass())->parse($content, $options);
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
     * Writes the subtitle in $format and throws on an image cue without text, unless the format writes images.
     */
    public function toString(Format $format, WriteOptions $options = new WriteOptions()): string
    {
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
