<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use GlyphOcr\Exceptions\GlyphOcrException;
use GlyphOcr\GlyphDatabase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\FormatWriteOptions;
use SubtitleToolbox\Formatters\Options\AssOptions;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Karaoke\WordHighlight;
use SubtitleToolbox\Karaoke\WordHighlightOptions;
use SubtitleToolbox\Ocr\GlyphOcrEngine;
use SubtitleToolbox\Ocr\OcrEngine;
use SubtitleToolbox\Ocr\OcrEngineChooser;
use SubtitleToolbox\Ocr\TesseractOcrEngine;
use SubtitleToolbox\Profanity\MuteRange;
use SubtitleToolbox\Profanity\ProfanityFilter;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\Speakers\SpeakerLabelOptions;
use SubtitleToolbox\Speakers\SpeakerLabels;
use SubtitleToolbox\Speakers\SpeakerStyle;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class ConvertCommand extends WriteCommand
{
    private const SPEAKER_MODES = ["prefix", "dashes", "colours", "from-prefix"];

    private const MASKS = [
        "stars"        => ProfanityOptions::MASK_STARS,
        "first-letter" => ProfanityOptions::MASK_FIRST_LETTER,
        "remove"       => ProfanityOptions::MASK_REMOVE,
        "none"         => ProfanityOptions::MASK_NONE,
    ];

    private const CASES = ["upper", "lower", "sentence"];

    private const KARAOKE_OPTIONS = ["karaoke-style", "karaoke-mode", "karaoke-words"];

    private const KARAOKE_TAGS = ["k", "kf", "ko"];

    private const MUTE_OPTIONS = ["mute-edl", "mute-filter", "mute-padding"];

    private ?string $ocrEngine = null;

    private ?GlyphDatabase $ocrDatabase = null;

    private bool $warnedAboutOcrLanguage = false;

    private ?ProfanityOptions $profanity = null;

    private ?WordHighlightOptions $karaoke = null;

    /** @var list<array{string, string}> */
    private array $replacements = [];

    /** @var list<MuteRange> */
    private array $muteRanges = [];


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
            new Option("replace", "Replace FROM with TO in the text between tags, for example --replace colour=color. Repeatable.", "FROM=TO", null, true),
            Option::flag("regex", "Read each FROM of --replace as a regular expression with delimiters, such as /\\.{4,}/. TO can use \$1."),
            Option::flag("ignore-case", "Match FROM of --replace in any case."),
            Option::value("case", "MODE", "Change the case of the text between tags: upper, lower or sentence."),
            Option::value("case-language", "CODE", "Language for --case. tr and az map i to İ and ı to I."),
            Option::value("mask-words", "FILE", "Mask the words of this file, one per line, as ProfanityFilter does. A * at the end matches any ending."),
            Option::value("mask", "STYLE", "How --mask-words masks a word: stars, first-letter, remove, or none to keep the text. Default: stars."),
            Option::value("mute-edl", "FILE", "Write the times of the --mask-words matches to this EDL file, for Kodi and MPlayer to mute the audio."),
            Option::value("mute-filter", "FILE", "Write an FFmpeg volume filter that mutes the --mask-words matches to this file."),
            Option::value("mute-padding", "SECONDS", "Widen each mute range by this time on both sides. Default: 0."),
            Option::flag("karaoke", "Write one cue per word timestamp, with the active word styled, for players without karaoke."),
            Option::value("karaoke-style", "TAG", "Style of the active word for --karaoke: b, i, u, s or 'font color=\"#ffff00\"'. Default: u."),
            Option::value("karaoke-mode", "MODE", "word styles the active word, cumulative all words up to it. Default: word."),
            Option::value("karaoke-words", "WORDS", "Show only this many words around the active word with --karaoke."),
            Option::value("karaoke-tag", "TAG", "Write word timestamps as ASS karaoke tags \\k, \\kf or \\ko: k, kf or ko. Default: k."),
            Option::flag("forced-only", "Keep only the forced cues, for example the translations of signs."),
            Option::flag("ocr", "Read the text of image cues, for example from PGS or VobSub, with Tesseract when it is installed, else with php-glyph-ocr."),
            Option::value("ocr-engine", "ENGINE", "The OCR engine for --ocr: tesseract or glyph. Default: tesseract when it is installed."),
            Option::value("ocr-language", "CODE", "The Tesseract language for --ocr, for example deu or deu+eng. Default: eng. The glyph engine ignores it."),
            Option::value("ocr-database", "FILE", "The .nocr glyph database for --ocr. It selects the glyph engine. Default: the subtitle fonts database of php-glyph-ocr."),
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

        $this->replacements = [];
        foreach ($arguments->values("replace") as $pair) {
            if (!str_contains($pair, "=") || str_starts_with($pair, "=")) {
                self::fail("The option --replace needs FROM=TO, got \"$pair\".");
            }
            [$from]      = explode("=", $pair, 2);
            if ($arguments->has("regex") && @preg_match($from, "") === false) {
                self::fail("The option --replace has an invalid regular expression: $from");
            }
            $this->replacements[] = explode("=", $pair, 2);
        }
        foreach (["regex", "ignore-case"] as $option) {
            if ($arguments->has($option) && $this->replacements === []) {
                self::fail("Pass --replace with --$option.");
            }
        }
        $case = $arguments->value("case");
        if ($case !== null && !in_array($case, self::CASES, true)) {
            self::fail("Unknown case \"$case\". Known cases: " . implode(", ", self::CASES) . ".");
        }
        if ($arguments->has("case-language") && $case === null) {
            self::fail("Pass --case with --case-language.");
        }

        $mask = $arguments->value("mask") ?? "stars";
        if (!isset(self::MASKS[$mask])) {
            self::fail("Unknown mask \"$mask\". Known masks: " . implode(", ", array_keys(self::MASKS)) . ".");
        }
        if ($arguments->has("mask") && !$arguments->has("mask-words")) {
            self::fail("Pass --mask-words with --mask.");
        }
        foreach (self::MUTE_OPTIONS as $option) {
            if ($arguments->has($option) && !$arguments->has("mask-words")) {
                self::fail("Pass --mask-words with --$option.");
            }
        }
        foreach (["mute-edl", "mute-filter"] as $option) {
            $path = $arguments->value($option);
            if ($path === self::DASH) {
                self::fail("The option --$option needs a file path.");
            }
            if ($path !== null && file_exists($path) && !$arguments->has("force")) {
                self::fail("$path exists. Pass --force to overwrite it.");
            }
        }
        if (($arguments->float("mute-padding") ?? 0) < 0) {
            self::fail("The option --mute-padding must not be negative.");
        }
        $words           = $arguments->value("mask-words");
        $this->profanity = $words === null ? null : new ProfanityOptions(
            mask: self::MASKS[$mask],
            padding: $arguments->float("mute-padding") ?? 0.0,
            wordFile: $words,
        );

        $this->karaoke = null;
        foreach (self::KARAOKE_OPTIONS as $option) {
            if ($arguments->has($option) && !$arguments->has("karaoke")) {
                self::fail("Pass --karaoke with --$option.");
            }
        }
        if ($arguments->has("karaoke") && $arguments->has("karaoke-tag")) {
            self::fail("Pass only one of --karaoke and --karaoke-tag.");
        }
        $tag = $arguments->value("karaoke-tag");
        if ($tag !== null && !in_array($tag, self::KARAOKE_TAGS, true)) {
            self::fail("The option --karaoke-tag must be k, kf or ko, got \"$tag\".");
        }
        $mode = $arguments->value("karaoke-mode");
        if ($mode !== null && !in_array($mode, [WordHighlightOptions::MODE_WORD, WordHighlightOptions::MODE_CUMULATIVE], true)) {
            self::fail("The option --karaoke-mode must be word or cumulative, got \"$mode\".");
        }
        if ($arguments->has("karaoke")) {
            try {
                $this->karaoke = new WordHighlightOptions(
                    style: $arguments->value("karaoke-style") ?? "u",
                    mode: $mode ?? WordHighlightOptions::MODE_WORD,
                    maxWordsPerCue: $arguments->positiveInt("karaoke-words"),
                );
            } catch (InvalidArgumentException $exception) {
                self::fail($exception->getMessage());
            }
        }

        $this->ocrEngine              = null;
        $this->ocrDatabase            = null;
        $this->warnedAboutOcrLanguage = false;
        foreach (["ocr-database", "ocr-engine", "ocr-language"] as $option) {
            if ($arguments->has($option) && !$arguments->has("ocr")) {
                self::fail("Pass --ocr with --$option.");
            }
        }
        if ($arguments->has("ocr")) {
            $engine = $arguments->value("ocr-engine");
            if ($arguments->has("ocr-database")) {
                if ($engine === OcrEngineChooser::ENGINE_TESSERACT) {
                    self::fail("--ocr-database works only with the glyph engine.");
                }
                $engine = OcrEngineChooser::ENGINE_GLYPH;
            }
            try {
                $this->ocrEngine = OcrEngineChooser::choose($engine);
            } catch (InvalidArgumentException $exception) {
                self::fail($exception->getMessage());
            }
            if ($this->ocrEngine === OcrEngineChooser::ENGINE_GLYPH) {
                $this->ocrDatabase = self::loadOcrDatabase($arguments->value("ocr-database"));
            }
        }
    }


    protected function needsWordTimestamps(Arguments $arguments): bool
    {
        return parent::needsWordTimestamps($arguments) || $arguments->has("karaoke") || $arguments->has("karaoke-tag");
    }


    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        parent::checkInputs($inputs, $arguments);

        if (count($inputs) > 1 && ($arguments->has("mute-edl") || $arguments->has("mute-filter"))) {
            self::fail("--mute-edl and --mute-filter take one input file, got " . count($inputs) . ".");
        }
    }


    protected function defaultTarget(string $input, string $fileName): string
    {
        $directory = dirname($input);

        return $directory === "." && !str_starts_with($input, ".") ? $fileName : "$directory/$fileName";
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        if ($arguments->has("forced-only")) {
            $subtitle = $subtitle->onlyForced();
        }

        if ($this->ocrEngine !== null) {
            $total = count(array_filter($subtitle->getCues(),
                                        fn (SubtitleCue $cue): bool => CueImage::isImageCue($cue) && $cue->getLines() === []));
            if ($total > 0) {
                $subtitle->recognizeText(new OcrProgress($this->ocrEngine($arguments, $console), $console, self::label($input), $total));
            }
        }

        parent::process($input, $subtitle, $format, $arguments, $console);

        $files = [
            "mute-edl"    => MuteRange::toEdl($this->muteRanges),
            "mute-filter" => MuteRange::toFfmpegVolumeFilter($this->muteRanges),
        ];
        foreach ($files as $option => $content) {
            $path = $arguments->value($option);
            if ($path === null) {
                continue;
            }
            if (@file_put_contents($path, $content === "" || str_ends_with($content, "\n") ? $content : "$content\n") === false) {
                self::fail("Cannot write $path.");
            }
            $this->report($console, self::label($input) . " -> $path\n");
        }
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments): void
    {
        $speakers = match ($arguments->value("speakers")) {
            "prefix"      => new SpeakerLabelOptions(to: SpeakerStyle::Prefix),
            "dashes"      => new SpeakerLabelOptions(to: SpeakerStyle::DialogueDashes),
            "colours"     => new SpeakerLabelOptions(to: SpeakerStyle::Colours),
            "from-prefix" => new SpeakerLabelOptions(from: SpeakerStyle::Prefix),
            null          => null,
        };
        if ($speakers !== null) {
            SpeakerLabels::apply($subtitle, $speakers);
        }
        foreach ($this->replacements as [$from, $to]) {
            $subtitle->replaceText($from, $to, $arguments->has("regex"), !$arguments->has("ignore-case"));
        }
        if ($arguments->has("case")) {
            $subtitle->changeCase($arguments->value("case"), $arguments->value("case-language"));
        }
        if ($this->profanity !== null) {
            $this->muteRanges = ProfanityFilter::apply($subtitle, $this->profanity)->muteRanges;
        }
        if ($arguments->has("strip-tags")) {
            $subtitle->stripFormatting();
        }
        if ($this->karaoke !== null) {
            WordHighlight::apply($subtitle, $this->karaoke);
        }
    }


    protected function commandFormatterOptions(Format $outputFormat, Arguments $arguments): ?FormatWriteOptions
    {
        $tag = $arguments->value("karaoke-tag");
        if ($tag === null) {
            return null;
        }
        if ($outputFormat !== Format::Ass) {
            self::fail("--karaoke-tag needs ASS output.");
        }

        return new AssOptions($tag);
    }


    private function usesPositionalOutput(Arguments $arguments): bool
    {
        return count($arguments->positionals) === 2 && !$arguments->has("to")
            && !$arguments->has("output") && !$arguments->has("output-dir");
    }


    private function ocrEngine(Arguments $arguments, Console $console): OcrEngine
    {
        if ($this->ocrEngine === OcrEngineChooser::ENGINE_TESSERACT) {
            return new TesseractOcrEngine($arguments->value("ocr-language") ?? "eng");
        }
        if ($arguments->has("ocr-language") && !$this->warnedAboutOcrLanguage) {
            $console->err("Warning: the glyph engine ignores --ocr-language.\n");
            $this->warnedAboutOcrLanguage = true;
        }

        // A new engine for each file, because the recognizer learns the glyph heights of one stream.
        return new GlyphOcrEngine($this->ocrDatabase);
    }


    private static function loadOcrDatabase(?string $path): GlyphDatabase
    {
        try {
            return $path === null ? GlyphDatabase::subtitleFonts() : GlyphDatabase::fromFile($path);
        } catch (GlyphOcrException $exception) {
            return self::fail($exception->getMessage());
        }
    }
}
