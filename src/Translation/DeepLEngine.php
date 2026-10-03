<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Exceptions\TranslationException;
use SubtitleToolbox\Http\CurlHttpClient;
use SubtitleToolbox\Http\HttpClient;

final class DeepLEngine implements TranslationEngine
{
    public const MAX_TEXTS_PER_REQUEST = 50;

    private readonly string $url;

    private readonly HttpClient $client;


    /**
     * Creates the engine for DeepL API v2. Without $baseUrl, a key that ends in ":fx" goes to the free API host,
     * as the official DeepL client libraries do. Without $client, it uses CurlHttpClient.
     */
    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        ?string $baseUrl = null,
        ?HttpClient $client = null,
    ) {
        $baseUrl      ??= str_ends_with($apiKey, ":fx") ? "https://api-free.deepl.com" : "https://api.deepl.com";
        $this->url    = rtrim($baseUrl, "/") . "/v2/translate";
        $this->client = $client ?? new CurlHttpClient();
    }


    /**
     * Translates the texts with tag_handling "xml". An empty $sourceLanguage lets DeepL detect the language.
     */
    public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
    {
        $translations = [];
        foreach (array_chunk($texts, self::MAX_TEXTS_PER_REQUEST) as $chunk) {
            array_push($translations, ...$this->request($chunk, $sourceLanguage, $targetLanguage));
        }

        return $translations;
    }


    /**
     * @param list<string> $texts
     * @return list<string>
     */
    private function request(array $texts, string $sourceLanguage, string $targetLanguage): array
    {
        $body = ["text" => $texts, "target_lang" => strtoupper($targetLanguage), "tag_handling" => "xml"];
        if ($sourceLanguage !== "") {
            $body["source_lang"] = strtoupper($sourceLanguage);
        }

        [$status, $response] = $this->client->post(
            $this->url,
            ["Authorization: DeepL-Auth-Key $this->apiKey", "Content-Type: application/json"],
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        );

        $data = json_decode($response, true);
        if ($status !== 200) {
            $detail = is_array($data) && is_string($data["message"] ?? null) ? " " . $data["message"] : "";
            throw new TranslationException(str_replace($this->apiKey, "***", match ($status) {
                403     => "DeepL rejected the API key (HTTP 403). Check the key and its plan.",
                429     => "DeepL got too many requests (HTTP 429). Wait and try again.",
                456     => "The DeepL character quota is used up (HTTP 456).",
                default => "DeepL answered with HTTP $status.$detail",
            }));
        }

        $results = is_array($data) && is_array($data["translations"] ?? null) ? $data["translations"] : [];
        $translations  = array_map(fn (mixed $result): mixed => is_array($result) ? ($result["text"] ?? null) : null, $results);
        if (!array_is_list($translations) || count($translations) !== count($texts) || count(array_filter($translations, "is_string")) !== count($texts)) {
            throw new TranslationException("DeepL returned " . count($translations) . " translations for " . count($texts) .
                                           " texts, or an answer that is not the JSON of /v2/translate.");
        }

        return $translations;
    }
}
