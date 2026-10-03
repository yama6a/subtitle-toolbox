<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\TranslationException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Translation\DeepLEngine;
use SubtitleToolbox\Translation\GoogleTranslateEngine;
use SubtitleToolbox\Translation\TranslationEngine;
use SubtitleToolbox\Translation\TranslationRunner;

class TranslateCommand extends WriteCommand
{
    private const KEY_VARIABLES = ["deepl" => "DEEPL_API_KEY", "google" => "GOOGLE_TRANSLATE_API_KEY"];

    // Points the engines at a local fake server in the tests. The help does not list it.
    private const URL_VARIABLE = "SUBTITLE_TOOLBOX_TRANSLATE_URL";

    private TranslationEngine $engine;

    private string $sourceLanguage = "";

    private string $targetLanguage = "";


    public function name(): string
    {
        return "translate";
    }


    public function summary(): string
    {
        return "Translates the cue text with DeepL or Google Cloud Translation.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --engine deepl|google --target-language CODE [--source-language CODE] [--api-key KEY] [options]"];
    }


    protected function details(): string
    {
        return "The API key comes from --api-key, else from DEEPL_API_KEY for deepl or GOOGLE_TRANSLATE_API_KEY for google.\n" .
               "The engines need the PHP extension curl. Without --output, --output-dir or --in-place, the result of one\n" .
               "input file goes to standard output.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("engine", "deepl|google", "Translation service. Required."),
            Option::value("api-key", "KEY", "API key of the engine. Default: the environment variable of the engine."),
            Option::value("source-language", "CODE", "Language of the input, for example de. Default: the engine detects it."),
            Option::value("target-language", "CODE", "Language of the output, for example en-US for DeepL or fr for Google. Required."),
        ];
    }


    public function execute(Arguments $arguments, Console $console): int
    {
        try {
            return parent::execute($arguments, $console);
        } catch (TranslationException $exception) {
            $console->err("Error: " . $exception->getMessage() . "\n");

            return Application::EXIT_FAILURE;
        }
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $engine   = strtolower($arguments->value("engine") ?? "") ?: self::fail("Pass --engine deepl or --engine google.");
        $variable = self::KEY_VARIABLES[$engine] ?? self::fail("The option --engine must be deepl or google, got \"$engine\".");
        $apiKey   = $arguments->value("api-key") ?? (getenv($variable) ?: null)
            ?? self::fail("Pass --api-key or set the environment variable $variable.");

        $this->targetLanguage = $arguments->value("target-language") ?? self::fail("Pass --target-language, for example --target-language fr.");
        $this->sourceLanguage = $arguments->value("source-language") ?? "";

        $baseUrl      = getenv(self::URL_VARIABLE) ?: null;
        $this->engine = $engine === "deepl" ? new DeepLEngine($apiKey, $baseUrl) : new GoogleTranslateEngine($apiKey, $baseUrl);
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $runner     = new TranslationRunner($this->engine);
        $translated = $runner->translate($subtitle, $this->sourceLanguage, $this->targetLanguage);
        foreach ($runner->getWarnings() as $warning) {
            $console->err(self::label($input) . ": cue " . ($warning->cueIndex + 1) . ": $warning->message\n");
        }

        parent::process($input, $translated, $format, $arguments, $console);
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments): void
    {
    }
}
