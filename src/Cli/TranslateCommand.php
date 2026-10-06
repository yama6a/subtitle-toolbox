<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Translation\DeepLEngine;
use SubtitleToolbox\Translation\DeepLOptions;
use SubtitleToolbox\Translation\GoogleTranslateEngine;
use SubtitleToolbox\Translation\GoogleTranslateOptions;
use SubtitleToolbox\Translation\TranslationRunner;

/**
 * @internal
 */
final class TranslateCommand extends WriteCommand
{
    private const KEY_VARIABLES = ["deepl" => "DEEPL_API_KEY", "google" => "GOOGLE_TRANSLATE_API_KEY"];

    // Points the engines at a local fake server in the tests. The help does not list it.
    private const URL_VARIABLE = "SUBTITLE_TOOLBOX_TRANSLATE_URL";

    // The variable must not send the key to another machine.
    private const LOCAL_HOSTS = ["127.0.0.1", "localhost", "[::1]"];

    private ?TranslationRunner $runner = null;

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
               "The engines need the PHP extension curl. One input file goes to standard output, or to the file of -o.\n" .
               "Several input files need --output-dir.";
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


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $engine   = $arguments->choice("engine", array_keys(self::KEY_VARIABLES)) ?? self::fail("Pass --engine deepl or --engine google.");
        $variable = self::KEY_VARIABLES[$engine];
        $apiKey   = $arguments->value("api-key") ?? (getenv($variable) ?: null)
            ?? self::fail("Pass --api-key or set the environment variable $variable.");

        if (trim($apiKey) === "") {
            self::fail("The API key is empty. Pass --api-key or set $variable.");
        }
        if (trim($apiKey) !== $apiKey || preg_match('/[\x00-\x1F\x7F]/', $apiKey) === 1) {
            self::fail("The API key has a control character, or a space at the start or end. Pass the key without them.");
        }

        $this->targetLanguage = $arguments->value("target-language") ?? self::fail("Pass --target-language, for example --target-language fr.");
        $this->sourceLanguage = $arguments->value("source-language") ?? "";

        $baseUrl      = self::localTestUrl();
        $this->runner = new TranslationRunner($engine === "deepl"
            ? new DeepLEngine(new DeepLOptions(apiKey: $apiKey, baseUrl: $baseUrl))
            : new GoogleTranslateEngine(new GoogleTranslateOptions(apiKey: $apiKey, baseUrl: $baseUrl)));
    }


    /**
     * Returns the scheme, host and port of SUBTITLE_TOOLBOX_TRANSLATE_URL when the host is this machine, else null.
     */
    public static function localTestUrl(): ?string
    {
        $parts = parse_url((string)getenv(self::URL_VARIABLE));
        if (!is_array($parts) || !in_array(strtolower($parts["scheme"] ?? ""), ["http", "https"], true)
            || !in_array(strtolower($parts["host"] ?? ""), self::LOCAL_HOSTS, true)) {
            return null;
        }

        // Only the parsed parts go to curl, so that curl cannot read another host from the URL.
        return "{$parts["scheme"]}://{$parts["host"]}" . (isset($parts["port"]) ? ":{$parts["port"]}" : "");
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments, Console $console, string $input): Subtitle
    {
        $report = $this->runner->translate($subtitle, $this->sourceLanguage, $this->targetLanguage);
        foreach ($report->warnings as $warning) {
            $console->err(self::label($input) . ": cue " . ($warning->cueIndex + 1) . ": $warning->message\n");
        }

        return $subtitle;
    }
}
