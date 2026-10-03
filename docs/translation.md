# Translation

`TranslationRunner` sends the cue text to a machine translation engine and returns a translated copy. The `language` metadata of the copy becomes the target language. The original subtitle does not change.

```php
use SubtitleToolbox\Translation\TranslationOptions;
use SubtitleToolbox\Translation\TranslationRunner;

$runner  = new TranslationRunner(new DeepLEngine($apiKey));
$english = $runner->translate($german, 'de', 'en-US');
$english = $runner->translate($german, 'de', 'en-US', new TranslationOptions(
    joinSentences: true,              // send cues of one sentence as one text
    maxCuesPerSentence: 3,            // most cues in one text
    maxCharactersPerRequest: 5000,    // most characters in one engine call
));
$runner->getWarnings();               // list of TranslationWarning with cueIndex and message
```

- **Sentences**: a cue that does not end with `.`, `?`, `!`, the ellipsis U+2026 or a CJK end mark such as U+3002 joins the next cue. Cue 1 `The train to Basel leaves` and cue 2 `from platform 4.` go out as one text. The runner splits the translation back in proportion to the characters of the cues, at a space. In Chinese, Japanese and Thai text it splits between two characters.
- **Lines**: a cue that goes out alone keeps its line breaks when the engine keeps them. Cues that go out as one text come back with one line each. Call `wrapLines()` to break long lines again.
- **Tags**: the runner replaces tags with numbered placeholders, for example `<i>Run!</i>` becomes `<x1>Run!</x1>`, and a word timestamp becomes `<x2/>`. It restores the tags after the translation. A tag that spans two cues of one sentence closes at the end of the first cue and opens again in the next.
- **Dropped placeholder**: when the engine drops, adds or breaks a placeholder, the runner removes all tags of the text and adds a `TranslationWarning` for each cue.
- **Not sent**: cues with only numbers, punctuation, symbols such as the music note U+266A, or no text keep their text.
- **Requests**: each engine call gets whole texts up to `maxCharactersPerRequest` characters. A longer text goes out alone.
- **Engine errors**: `translate()` throws `InvalidArgumentException` when the engine does not return one string per text. Exceptions of the engine pass through.

## Engines
The library ships 2 engines. Both send plain HTTP requests through the PHP extension curl. No vendor SDK is needed.

```php
use SubtitleToolbox\Translation\DeepLEngine;
use SubtitleToolbox\Translation\GoogleTranslateEngine;
use SubtitleToolbox\Translation\TranslationRunner;

$german  = Subtitle::load('movie.de.srt', Format::SubRip);
$english = (new TranslationRunner(new DeepLEngine($apiKey)))->translate($german, 'de', 'en-US');
$french  = (new TranslationRunner(new GoogleTranslateEngine($apiKey)))->translate($german, 'de', 'fr');
$english->save('movie.en.srt');
```

| Engine | Service | Key | Tags |
|:--- |:--- |:--- |:--- |
| `DeepLEngine` | DeepL API v2 | header `Authorization: DeepL-Auth-Key`. A key that ends in `:fx` goes to `api-free.deepl.com` | `tag_handling: "xml"` |
| `GoogleTranslateEngine` | Cloud Translation Basic (v2) | query parameter `key` | `format: "html"`. The engine decodes entities such as `&#39;` in the answer |

- **Constructor**: `new DeepLEngine($apiKey, $baseUrl, $client)`. `$baseUrl` replaces the host of the service, for example for a proxy. `$client` is an `HttpClient`. Default: `CurlHttpClient`.
- **Source language**: an empty string lets the service detect the language.
- **Request size**: DeepL takes at most 50 texts per request, Google at most 128. The engines split a longer list and join the results in order. `maxCharactersPerRequest`, default 5,000, keeps each request below the size limits of both services.
- **Errors**: the engines throw `TranslationException` for HTTP errors such as 403 (wrong key), 429 (too many requests) and 456 (DeepL quota used up), and for an answer they cannot read. The message names the cause and never holds the key.
- **No curl**: without `ext-curl`, `CurlHttpClient` throws `TranslationException` with a message that names the extension. Composer lists `ext-curl` under `suggest` only, because the rest of the library runs without it.
- **Google v3**: the engine uses v2, because v3 needs an OAuth access token and a project ID in place of an API key.

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
