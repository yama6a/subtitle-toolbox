# Translation

`TranslationRunner` sends the cue text to a machine translation engine and writes the translation into the cues. The `language` metadata becomes the target language. Pass a clone to keep the original.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Translation\DeepLEngine;
use SubtitleToolbox\Translation\DeepLOptions;
use SubtitleToolbox\Translation\TranslationOptions;
use SubtitleToolbox\Translation\TranslationRunner;

$german  = Subtitle::load('movie.de.srt', Format::SubRip);
$runner  = new TranslationRunner(new DeepLEngine(new DeepLOptions(apiKey: $apiKey)));
$english = clone $german;
$report  = $runner->translate($english, 'de', 'en-US', new TranslationOptions(   // TranslationOptions is optional
    joinSentences: true,              // send cues of one sentence as one text
    maxCuesPerSentence: 3,            // at most 3 cues in one text
    maxCharactersPerRequest: 5000,    // at most 5,000 characters in one engine call
));
$report->warnings;                    // list of TranslationWarning with cueIndex and message
$english->save('movie.en.srt');
```

- **Sentences**: a cue joins the next cue when it does not end a sentence. A sentence ends with `.`, `?`, `!`, the ellipsis U+2026 or a CJK end mark such as U+3002. Cue 1 `The train to Basel leaves` and cue 2 `from platform 4.` go out as one text. The runner splits the translation back in proportion to the characters of the cues, at a space. In Chinese, Japanese and Thai text it splits between two characters.
- **Lines**: a cue that goes out alone keeps its line breaks when the engine keeps them. `DeepLEngine` sends `split_sentences: "nonewlines"`, so DeepL translates the 2 lines of a cue as one sentence. Cues that go out as one text come back with one line each. Call `wrapLines()` to break long lines again.
- **Tags**: the runner replaces tags with numbered placeholders, for example `<i>Run!</i>` becomes `<x1>Run!</x1>`, and a word timestamp becomes `<x2/>`. It restores the tags after the translation. A tag can span two cues of one sentence. It then closes at the end of the first cue and opens again in the next.
- **Dropped placeholder**: the engine can drop, add or break a placeholder. The runner then removes all tags of the text and adds a `TranslationWarning` for each cue.
- **Not sent**: cues with only numbers, punctuation, symbols such as the music note U+266A, or no text keep their text.
- **Requests**: each engine call gets whole texts up to `maxCharactersPerRequest` characters. A longer text goes out alone.
- **Engine errors**: `translate()` throws `InvalidArgumentException` when the engine does not return one string per text. Exceptions of the engine pass through. The subtitle changes only after the last engine call succeeds.

## Engines
The library ships 2 engines. Both send plain HTTP requests through the PHP extension curl. No vendor SDK is needed.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Translation\DeepLEngine;
use SubtitleToolbox\Translation\DeepLOptions;
use SubtitleToolbox\Translation\GoogleTranslateEngine;
use SubtitleToolbox\Translation\GoogleTranslateOptions;
use SubtitleToolbox\Translation\TranslationRunner;

$deepL  = new DeepLEngine(new DeepLOptions(apiKey: $deepLKey));
$google = new GoogleTranslateEngine(new GoogleTranslateOptions(apiKey: $googleKey, baseUrl: 'https://proxy.example.com'));

$english = Subtitle::load('movie.de.srt', Format::SubRip);
(new TranslationRunner($deepL))->translate($english, 'de', 'en-US');
$english->save('movie.en.srt');

$french = Subtitle::load('movie.de.srt', Format::SubRip);
(new TranslationRunner($google))->translate($french, 'de', 'fr');
$french->save('movie.fr.srt');
```

| Engine | Options | Service | Key | Tags |
|:--- |:--- |:--- |:--- |:--- |
| `DeepLEngine` | `DeepLOptions` | DeepL API v2 | header `Authorization: DeepL-Auth-Key`. A key that ends in `:fx` goes to `api-free.deepl.com` | `tag_handling: "xml"` |
| `GoogleTranslateEngine` | `GoogleTranslateOptions` | Cloud Translation Basic (v2) | header `X-goog-api-key` | `format: "html"`. The runner decodes entities such as `&#39;` in the answer |

| Option | Default | Sets |
|:--- |:--- |:--- |
| `apiKey` | required | the API key of the service. These keys throw `InvalidArgumentException`: an empty key, a key with a control character such as a line break, and a key with a space at the start or end |
| `baseUrl` | null, the host of the service | the scheme and host for the requests, for example a proxy. It must start with `http://` or `https://` |
| `httpClient` | null, a client that uses `ext-curl` | the `HttpClient` that sends the requests |

- **Language codes**: the engines pass the codes to the service as they are. DeepL gets them in upper case, for example `EN-US`, because its API expects that. DeepL takes a region only in the target language, so `DeepLEngine` sends the source language `en-US` as `EN`. The engines do not check the codes. The service rejects an unknown code.
- **Source language**: an empty string lets the service detect the language.
- **Request size**: DeepL takes at most 50 texts per request. `GoogleTranslateEngine` sends at most 128 texts per request. The engines split a longer list and join the results in order. `maxCharactersPerRequest`, default 5,000, keeps each request below the size limits of both services.
- **Errors**: the engines throw `TranslationException` in these cases. The message names the cause and never holds the key.
  - An HTTP error, such as 403 for a wrong key, 429 for too many requests or 456 for a used-up DeepL quota.
  - A request that gets no response.
  - An answer that the engine cannot read.
- **No curl**: without `ext-curl` and without an `httpClient`, the engine constructor throws `InvalidArgumentException` with a message that names the extension. Check `extension_loaded('curl')` before you create an engine. Composer lists `ext-curl` under `suggest` only, because the rest of the library runs without it.
- **Google v3**: the engine uses v2. v3 needs an OAuth access token and a project ID in place of an API key.

## Your own HTTP client
Implement `HttpClient` to send the requests with another HTTP library, or to log them. Its method `post()` returns the status code and the body of the response:

```php
use SubtitleToolbox\Exceptions\TranslationException;
use SubtitleToolbox\Http\HttpClient;

final class StreamHttpClient implements HttpClient
{
    public function post(string $url, array $headers, string $body): array
    {
        $context  = stream_context_create(['http' => ['method' => 'POST', 'header' => $headers, 'content' => $body, 'ignore_errors' => true]]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new TranslationException('The request failed.');
        }

        return [(int) explode(' ', $http_response_header[0])[1], $response];
    }
}
```

- **Error statuses**: return them as they are, for example `[403, $body]`. The engine turns them into `TranslationException`.
- **No response**: throw `TranslationException`, or another exception that your code catches.

## Your own engine
An engine is a class that implements `TranslationEngine`:

```php
use SubtitleToolbox\Translation\TranslationEngine;

final class GlossaryEngine implements TranslationEngine
{
    public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
    {
        return array_map(fn (string $text): string => str_replace('train', 'Zug', $text), $texts);
    }
}
```

- **Texts**: each text holds placeholders and the entities `&lt;`, `&gt;` and `&amp;`, so it is valid XML content. Tell the service to keep tags, for example with `tag_handling` for DeepL.
- **Answer**: return one string per text, in the same order. The runner decodes other entities in the answer, such as `&#39;`, and escapes a bare `&`, `<` or `>`.
