<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Http\HttpClient;

/**
 * The settings of GoogleTranslateEngine.
 */
final readonly class GoogleTranslateOptions
{
    /**
     * @param string          $apiKey     an API key of a Google Cloud project with the Cloud Translation API
     * @param string|null     $baseUrl    the scheme and host of the service, for example a proxy, or null for
     *                                    https://translation.googleapis.com
     * @param HttpClient|null $httpClient the client that sends the requests, or null for one that uses ext-curl
     */
    public function __construct(
        #[\SensitiveParameter] public string $apiKey,
        public ?string $baseUrl = null,
        public ?HttpClient $httpClient = null,
    ) {
        if (trim($apiKey) === "") {
            throw new InvalidArgumentException("Cannot create GoogleTranslateOptions with an empty API key - pass the key of " .
                                               "your Google Cloud project!");
        }
        if ($baseUrl !== null && preg_match('#^https?://[^/]#i', $baseUrl) !== 1) {
            throw new InvalidArgumentException("Cannot create GoogleTranslateOptions with the base URL \"$baseUrl\" - " .
                                               "the URL must start with http:// or https://!");
        }
    }
}
