<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Http\HttpClient;

/**
 * Sends a request of a translation engine again after HTTP 429, 500, 502, 503 or 504.
 *
 * @internal
 */
final class HttpRetry
{
    // HttpClient::post() returns no headers, so the waits cannot follow Retry-After.
    private const WAIT_SECONDS = [1, 2, 4];

    private const RETRY_STATUSES = [429, 500, 502, 503, 504];

    /**
     * Replaces sleep() in the tests.
     *
     * @var (\Closure(int): void)|null
     */
    public static ?\Closure $sleep = null;


    /**
     * Calls $client->post() and calls it again up to 3 times, after 1, 2 and 4 seconds, while the status asks for a retry.
     *
     * @param list<string> $headers
     * @return array{0: int, 1: string}
     */
    public static function post(HttpClient $client, string $url, array $headers, string $body): array
    {
        foreach (self::WAIT_SECONDS as $seconds) {
            $response = $client->post($url, $headers, $body);
            if (!in_array($response[0], self::RETRY_STATUSES, true)) {
                return $response;
            }
            (self::$sleep ?? sleep(...))($seconds);
        }

        return $client->post($url, $headers, $body);
    }
}
