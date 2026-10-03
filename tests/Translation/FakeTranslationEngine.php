<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

final class FakeTranslationEngine implements TranslationEngine
{
    /** @var list<array{texts: list<string>, source: string, target: string}> */
    public array $calls = [];


    public function __construct(private readonly bool $dropPlaceholders = false)
    {
    }


    public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
    {
        $this->calls[] = ["texts" => $texts, "source" => $sourceLanguage, "target" => $targetLanguage];

        return array_map(fn (string $text): string => $this->translateText($text), $texts);
    }


    private function translateText(string $text): string
    {
        if ($this->dropPlaceholders) {
            $text = preg_replace('/<\/?x\d+\/?>/', "", $text);
        }

        return preg_replace_callback(
            '/(<[^<>]*>|&[#\w]+;)|([^<&]+)/',
            fn (array $match): string => $match[1] !== "" ? $match[1] : strtoupper($match[2]),
            $text
        );
    }
}
