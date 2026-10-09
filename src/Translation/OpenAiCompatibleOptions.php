<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Http\HttpClient;

/**
 * The settings of OpenAiCompatibleEngine.
 */
final readonly class OpenAiCompatibleOptions
{
    /**
     * @param string          $baseUrl    the URL in front of /chat/completions, for example https://api.openai.com/v1 or
     *                                    http://localhost:11434/v1 for Ollama
     * @param string          $model      the model name that the service knows, for example gpt-4o-mini or llama3
     * @param string|null     $apiKey     the key for the header "Authorization: Bearer", or null to send no such header
     * @param string|null     $prompt     the system prompt, or null for the built-in prompt. "{source}" and "{target}" in it
     *                                    become the language codes. An empty source code becomes "the language of the text"
     * @param HttpClient|null $httpClient the client that sends the requests, or null for one that uses ext-curl
     */
    public function __construct(
        public string $baseUrl,
        public string $model,
        #[\SensitiveParameter] public ?string $apiKey = null,
        public ?string $prompt = null,
        public ?HttpClient $httpClient = null,
    ) {
        if (preg_match('#^https?://[^/]#i', $baseUrl) !== 1) {
            throw new InvalidArgumentException("The base URL must start with http:// or https://, got \"$baseUrl\".");
        }
        if (trim($model) === "") {
            throw new InvalidArgumentException("The model must not be empty. Pass a model name such as gpt-4o-mini or llama3.");
        }
        if ($apiKey !== null && (trim($apiKey) === "" || trim($apiKey) !== $apiKey || preg_match('/[\x00-\x1F\x7F]/', $apiKey) === 1)) {
            throw new InvalidArgumentException("The API key must not be empty, have a control character, or a space at the start or end. " .
                                               "Pass null for a service without a key.");
        }
        if ($prompt !== null && trim($prompt) === "") {
            throw new InvalidArgumentException("The prompt must not be empty. Pass null for the built-in prompt.");
        }
    }
}
