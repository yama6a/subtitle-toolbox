<?php

declare(strict_types=1);

namespace SubtitleToolbox\Http;

use SubtitleToolbox\Exceptions\TranslationException;

class CurlHttpClient implements HttpClient
{
    public const MISSING_CURL = "The translate engines need the PHP extension curl. Install ext-curl, for example php8.2-curl.";


    /**
     * Creates the client. It throws TranslationException when the PHP extension curl is missing.
     */
    public function __construct(
        private readonly int $connectTimeoutSeconds = 10,
        private readonly int $timeoutSeconds = 120,
    ) {
        if (!$this->curlLoaded()) {
            throw new TranslationException(self::MISSING_CURL);
        }
    }


    public function post(string $url, array $headers, string $body): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
        ]);
        $response = curl_exec($handle);
        if (!is_string($response)) {
            // The URL can hold an API key, so the message names the host only.
            throw new TranslationException("The request to " . parse_url($url, PHP_URL_HOST) . " failed: " . curl_error($handle));
        }

        return [curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $response];
    }


    protected function curlLoaded(): bool
    {
        return function_exists("curl_init");
    }
}
