<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Exceptions\TranslationException;
use SubtitleToolbox\Http\CurlHttpClient;
use SubtitleToolbox\Http\HttpClient;

final class GoogleTranslateEngine implements TranslationEngine
{
    private const MAX_TEXTS_PER_REQUEST = 128;

    private readonly string $url;

    private readonly string $apiKey;

    private readonly HttpClient $client;


    /**
     * Creates the engine for Cloud Translation Basic (v2) with an API key. It throws InvalidArgumentException when the
     * options pass no HttpClient and PHP has no ext-curl.
     */
    public function __construct(GoogleTranslateOptions $options)
    {
        $this->url    = rtrim($options->baseUrl ?? "https://translation.googleapis.com", "/") . "/language/translate/v2";
        $this->apiKey = $options->apiKey;
        $this->client = $options->httpClient ?? new CurlHttpClient();
    }


    /**
     * Translates the texts with format "html" and returns the answer as received. The runner decodes its entities.
     * An empty $sourceLanguage lets Google detect the language.
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
        $body = ["q" => $texts, "target" => $targetLanguage, "format" => "html"];
        if ($sourceLanguage !== "") {
            $body["source"] = $sourceLanguage;
        }

        [$status, $response] = $this->client->post(
            $this->url,
            ["X-goog-api-key: $this->apiKey", "Content-Type: application/json"],
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        );

        $data = json_decode($response, true);
        if ($status !== 200) {
            $detail = is_array($data) && is_string($data["error"]["message"] ?? null) ? " " . $data["error"]["message"] : "";
            throw new TranslationException(str_replace($this->apiKey, "***", match ($status) {
                403     => "Google Cloud Translation refused the request (HTTP 403). Check the key and that the API is enabled.$detail",
                429     => "Google Cloud Translation got too many requests (HTTP 429). Wait and try again.",
                default => "Google Cloud Translation answered with HTTP $status.$detail",
            }));
        }

        $results      = is_array($data) && is_array($data["data"]["translations"] ?? null) ? $data["data"]["translations"] : [];
        $translations = array_map(fn (mixed $result): mixed => is_array($result) ? ($result["translatedText"] ?? null) : null, $results);
        if (!array_is_list($translations) || count($translations) !== count($texts)
            || count(array_filter($translations, "is_string")) !== count($texts)) {
            throw new TranslationException("Google Cloud Translation returned " . count($translations) . " translations for " .
                                           count($texts) . " texts, or an answer that is not the JSON of translate v2.");
        }

        return $translations;
    }
}
