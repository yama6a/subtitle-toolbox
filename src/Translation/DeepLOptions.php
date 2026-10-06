<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Http\HttpClient;

/**
 * The settings of DeepLEngine.
 */
final readonly class DeepLOptions
{
    /**
     * @param string          $apiKey     the DeepL API key. A key that ends in ":fx" belongs to the free plan
     * @param string|null     $baseUrl    the scheme and host of the service, for example a proxy, or null for
     *                                    https://api-free.deepl.com with a free key and https://api.deepl.com otherwise
     * @param HttpClient|null $httpClient the client that sends the requests, or null for one that uses ext-curl
     */
    public function __construct(
        #[\SensitiveParameter] public string $apiKey,
        public ?string $baseUrl = null,
        public ?HttpClient $httpClient = null,
    ) {
        if (trim($apiKey) === "") {
            throw new InvalidArgumentException("Cannot create DeepLOptions with an empty API key - pass the key of " .
                                               "your DeepL account!");
        }
        if ($baseUrl !== null && preg_match('#^https?://[^/]#i', $baseUrl) !== 1) {
            throw new InvalidArgumentException("Cannot create DeepLOptions with the base URL \"$baseUrl\" - the URL " .
                                               "must start with http:// or https://!");
        }
    }
}
