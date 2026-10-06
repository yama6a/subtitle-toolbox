<?php

declare(strict_types=1);

namespace SubtitleToolbox\Http;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\TranslationException;

/**
 * The HttpClient of the engines when their options pass none.
 *
 * @internal
 */
final class CurlHttpClient implements HttpClient
{
    private const CONNECT_TIMEOUT_SECONDS = 10;

    private const TIMEOUT_SECONDS = 120;


    /**
     * Throws InvalidArgumentException when PHP has no ext-curl.
     */
    public function __construct()
    {
        if (!self::isAvailable()) {
            throw new InvalidArgumentException("PHP has no ext-curl, which the DeepL and Google engines need. " .
                                               "Install the PHP curl extension.");
        }
    }


    /**
     * Returns true when PHP has ext-curl.
     */
    public static function isAvailable(): bool
    {
        return function_exists("curl_init");
    }


    public function post(string $url, array $headers, string $body): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
        ]);
        $response = curl_exec($handle);
        if (!is_string($response)) {
            // A base URL can hold a user name and a password, so the message names the host only.
            throw new TranslationException("The request to " . parse_url($url, PHP_URL_HOST) . " failed: " . curl_error($handle));
        }

        return [curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $response];
    }
}
