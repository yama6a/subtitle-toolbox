<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\CueNotFoundException;
use SubtitleToolbox\Exceptions\ImageCueWithoutTextException;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\InvalidFormatterException;
use SubtitleToolbox\Exceptions\InvalidParserException;
use SubtitleToolbox\Formatters\ImageFormatter;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\OcrEngine;
use SubtitleToolbox\Ocr\OcrRunner;
use SubtitleToolbox\Parsers\SubtitleParser;


class Subtitle implements \IteratorAggregate, \Countable
{
    use Retiming;
    use Validation;
    use CueEditing;
    use Fixes;
    use TextTransforms;
    use HearingImpairedRemoval;
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


    public function __construct()
    {
        $this->cues = [];
    }


    /**
     * Parses $content with $parserClass, or with the parser that detectParser() returns when $parserClass is null.
     * A UTF-16 or UTF-32 BOM, or else $sourceEncoding such as "Windows-1252", sets the encoding to convert from.
     * A parser instance in place of the class name keeps its settings, such as lenient mode, and its warnings.
     */
    public static function parse(string $content, string|SubtitleParser|null $parserClass = null, ?string $sourceEncoding = null): self
    {
        $content = StringHelpers::convertToUtf8($content, $sourceEncoding);

        $parserClass ??= self::detectParser($content)
            ?? throw new InvalidParserException("The subtitle format of the content is unknown. Pass a parser class.");

        if ($parserClass instanceof SubtitleParser) {
            return $parserClass->parse($content);
        }

        if (!is_subclass_of($parserClass, SubtitleParser::class)) {
            throw new InvalidParserException("The supplied parser $parserClass " .
                                             "is not of type " . SubtitleParser::class);
        }

        return (new $parserClass())->parse($content);
    }


    /**
     * Returns the parser class for the format of $content, or null when no known format matches.
     */
    public static function detectParser(string $content): ?string
    {
        return FormatDetector::detect($content);
    }


    /**
     * Writes the subtitle with $formatterClass and throws on an image cue without text, unless the formatter is an ImageFormatter.
     */
    public function format(string $formatterClass, array $options = []): string
    {
        if (!is_subclass_of($formatterClass, SubtitleFormatter::class)) {
            throw new InvalidFormatterException("The supplied formatter $formatterClass " .
                                                "is not of type " . SubtitleFormatter::class);
        }

        $subtitle = $this;
        if (!is_subclass_of($formatterClass, ImageFormatter::class)) {
            $imageCueIndexes = array_keys(array_filter($this->cues, fn (SubtitleCue $cue): bool =>
                CueImage::isImageCue($cue) && $cue->getLines() === []));

            if ($imageCueIndexes !== [] && !($options[SubtitleFormatter::OPTION_SKIP_IMAGE_CUES] ?? false)) {
                throw new ImageCueWithoutTextException("Cue #{$imageCueIndexes[0]} holds an image but no text. " .
                                                       "Run recognizeText() first, or pass the option " .
                                                       "SubtitleFormatter::OPTION_SKIP_IMAGE_CUES.");
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


    public function getErrors(): array
    {
        $errors = [];

        if (count($this->cues) === 0) {
            $errors[] = "This subtitle contains no cues!";
        }

        $previousCueEnd   = 0;
        $previousCueIndex = -1;
        foreach ($this->cues as $cueIndex => $cue) {
            if ($cue->getStart() < $previousCueEnd) {
                $errors[] = "The start-time ({$cue->getStart()}) of cue #$cueIndex is " .
                            "before its predecessor's end-time ($previousCueEnd)! " .
                            "Try running reIndexCues() on the subtitle to fix it.";
            }
            $previousCueEnd = $cue->getEnd();

            if ($cueIndex !== ++$previousCueIndex) {
                $errors[] = "The cue-index of cue #$cueIndex is $cueIndex " .
                            "but we expected it to be $previousCueIndex! " .
                            "Try running reIndexCues() on the subtitle to fix it.";
            }

            if ($cue->getStart() > $cue->getEnd()) {
                $errors[] = "The start-time of cue #$cueIndex is after its own end-time!";
            }
        }

        return $errors;
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
