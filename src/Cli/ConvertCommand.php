<?php

namespace SubtitleToolbox\Cli;

use GlyphOcr\Exceptions\GlyphOcrException;
use GlyphOcr\GlyphDatabase;
use GlyphOcr\Recognizer;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\GlyphOcrEngine;
use SubtitleToolbox\Profanity\ProfanityFilter;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\Speakers\SpeakerLabels;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class ConvertCommand extends WriteCommand
{
    private const SPEAKER_MODES = ["prefix", "dashes", "colours", "from-prefix"];

    private const MASKS = [
        "stars"        => ProfanityOptions::MASK_STARS,
        "first-letter" => ProfanityOptions::MASK_FIRST_LETTER,
        "remove"       => ProfanityOptions::MASK_REMOVE,
    ];

    private ?GlyphDatabase $ocrDatabase = null;

    private ?ProfanityOptions $profanity = null;


    public function name(): string
    {
        return "convert";
    }


    public function summary(): string
    {
        return "Converts subtitle files to another format.";
    }


    protected function usageLines(): array
    {
        return ["<input> <output> [options]", "<input>... --to FORMAT [options]"];
    }


    protected function details(): string
    {
        return "With two arguments and no --to, the second argument is the output file, and its extension sets the format.\n" .
               "Without --output or --output-dir, each output file goes next to its input file, with the extension of\n" .
               "the output format. An input argument can be a file, a directory, a glob such as \"season1/*.srt\", or -.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::flag("strip-tags", "Remove all formatting tags, such as <i> and <font>, from the cue text."),
            Option::value("speakers", "MODE", "Convert <v> speaker tags: prefix (ANNA: Hi), dashes, colours, or from-prefix (ANNA: to <v Anna>)."),
            Option::value("mask-words", "FILE", "Mask the words of this file, one per line, as ProfanityFilter does. A * at the end matches any ending."),
            Option::value("mask", "STYLE", "How --mask-words masks a word: stars, first-letter or remove. Default: stars."),
            Option::flag("forced-only", "Keep only the forced cues, for example the translations of signs."),
            Option::flag("ocr", "Read the text of image cues, for example from PGS or VobSub, with GlyphOcrEngine."),
            Option::value("ocr-database", "FILE", "The .nocr glyph database for --ocr. Default: the Latin database of php-glyph-ocr."),
        ];
    }


    protected function allowsInPlace(): bool
    {
        return false;
    }


    protected function explicitOutput(Arguments $arguments): ?string
    {
        if ($this->usesPositionalOutput($arguments)) {
            return $arguments->positionals[1];
        }

        return parent::explicitOutput($arguments);
    }


    protected function inputArguments(Arguments $arguments): array
    {
        return $this->usesPositionalOutput($arguments) ? [$arguments->positionals[0]] : $arguments->positionals;
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        if ($this->toFormat === null && ($this->output === null || $this->output === self::DASH)) {
            self::fail("Pass --to FORMAT or an output file.");
        }

        $speakers = $arguments->value("speakers");
        if ($speakers !== null && !in_array($speakers, self::SPEAKER_MODES, true)) {
            self::fail("Unknown speaker mode \"$speakers\". Known modes: " . implode(", ", self::SPEAKER_MODES) . ".");
        }

        $mask = $arguments->value("mask") ?? "stars";
        if (!isset(self::MASKS[$mask])) {
            self::fail("Unknown mask \"$mask\". Known masks: " . implode(", ", array_keys(self::MASKS)) . ".");
        }
        if ($arguments->has("mask") && !$arguments->has("mask-words")) {
            self::fail("Pass --mask-words with --mask.");
        }
        $words           = $arguments->value("mask-words");
        $this->profanity = $words === null ? null : new ProfanityOptions(mask: self::MASKS[$mask], wordFile: $words);

        $this->ocrDatabase = $arguments->has("ocr") ? self::loadOcrDatabase($arguments->value("ocr-database")) : null;
        if ($this->ocrDatabase === null && $arguments->has("ocr-database")) {
            self::fail("Pass --ocr with --ocr-database.");
        }
    }


    protected function defaultTarget(string $input, string $fileName): string
    {
        $directory = dirname($input);

        return $directory === "." && !str_starts_with($input, ".") ? $fileName : "$directory/$fileName";
    }


    protected function process(string $input, Subtitle $subtitle, string $format, Arguments $arguments, Console $console): void
    {
        if ($arguments->has("forced-only")) {
            $subtitle = $subtitle->forcedOnly();
        }

        if ($this->ocrDatabase !== null) {
            $total = count(array_filter($subtitle->getCues(),
                                        fn (SubtitleCue $cue): bool => CueImage::isImageCue($cue) && $cue->getLines() === []));
            if ($total > 0) {
                // A new engine for each file, because the recognizer learns the glyph heights of one stream.
                $engine = new GlyphOcrEngine($this->ocrDatabase);
                $subtitle->recognizeText(new OcrProgress($engine, $console, self::label($input), $total));
            }
        }

        parent::process($input, $subtitle, $format, $arguments, $console);
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments): void
    {
        match ($arguments->value("speakers")) {
            "prefix"      => SpeakerLabels::toPrefix($subtitle),
            "dashes"      => SpeakerLabels::toDialogueDashes($subtitle),
            "colours"     => SpeakerLabels::toColours($subtitle),
            "from-prefix" => SpeakerLabels::fromPrefix($subtitle),
            null          => null,
        };
        if ($this->profanity !== null) {
            ProfanityFilter::apply($subtitle, $this->profanity);
        }
        if ($arguments->has("strip-tags")) {
            $subtitle->stripFormatting();
        }
    }


    private function usesPositionalOutput(Arguments $arguments): bool
    {
        return count($arguments->positionals) === 2 && !$arguments->has("to")
            && !$arguments->has("output") && !$arguments->has("output-dir");
    }


    private static function loadOcrDatabase(?string $path): GlyphDatabase
    {
        if (!class_exists(Recognizer::class)) {
            self::fail("--ocr needs the package yama6a/php-glyph-ocr. Install it with: composer require yama6a/php-glyph-ocr");
        }

        try {
            return $path === null ? GlyphDatabase::latin() : GlyphDatabase::fromFile($path);
        } catch (GlyphOcrException $exception) {
            return self::fail($exception->getMessage());
        }
    }
}
