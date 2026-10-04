# Translation

`TranslationRunner` sends the cue text to a machine translation engine and returns a translated copy. The `language` metadata of the copy becomes the target language. The original subtitle does not change.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Translation\TranslationOptions;
use SubtitleToolbox\Translation\TranslationRunner;

$german  = Subtitle::load('movie.de.srt', Format::SubRip);
$runner  = new TranslationRunner(new DeepLEngine($apiKey));   // DeepLEngine is your own engine, see Engines
$english = $runner->translate($german, 'de', 'en-US');
$english = $runner->translate($german, 'de', 'en-US', new TranslationOptions(
    joinSentences: true,              // send cues of one sentence as one text
    maxCuesPerSentence: 3,            // most cues in one text
    maxCharactersPerRequest: 5000,    // most characters in one engine call
));
$runner->getWarnings();               // list of TranslationWarning with cueIndex and message
$english->save('movie.en.srt');
```

- **Sentences**: a cue that does not end with `.`, `?`, `!`, the ellipsis U+2026 or a CJK end mark such as U+3002 joins the next cue. Cue 1 `The train to Basel leaves` and cue 2 `from platform 4.` go out as one text. The runner splits the translation back in proportion to the characters of the cues, at a space. In Chinese, Japanese and Thai text it splits between two characters.
- **Lines**: a cue that goes out alone keeps its line breaks when the engine keeps them. Cues that go out as one text come back with one line each. Call `wrapLines()` to break long lines again.
- **Tags**: the runner replaces tags with numbered placeholders, for example `<i>Run!</i>` becomes `<x1>Run!</x1>`, and a word timestamp becomes `<x2/>`. It restores the tags after the translation. A tag that spans two cues of one sentence closes at the end of the first cue and opens again in the next.
- **Dropped placeholder**: when the engine drops, adds or breaks a placeholder, the runner removes all tags of the text and adds a `TranslationWarning` for each cue.
- **Not sent**: cues with only numbers, punctuation, symbols such as the music note U+266A, or no text keep their text.
- **Requests**: each engine call gets whole texts up to `maxCharactersPerRequest` characters. A longer text goes out alone.
- **Engine errors**: `translate()` throws `InvalidArgumentException` when the engine does not return one string per text. Exceptions of the engine pass through.

## Engines
An engine is a class that implements `TranslationEngine`. The package ships no engine. This example engine uses [deeplcom/deepl-php](https://github.com/DeepLcom/deepl-php):

```php
use DeepL\DeepLClient;
use SubtitleToolbox\Translation\TranslationEngine;

final class DeepLEngine implements TranslationEngine
{
    private DeepLClient $client;


    public function __construct(string $authKey)
    {
        $this->client = new DeepLClient($authKey);
    }


    public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
    {
        $results = $this->client->translateText($texts, $sourceLanguage, $targetLanguage, ['tag_handling' => 'xml']);

        return array_map(fn ($result): string => $result->text, $results);
    }
}
```

- **Texts**: each text holds placeholders and the entities `&lt;`, `&gt;` and `&amp;`, so it is valid XML content. Tell the engine to keep tags, for example with `tag_handling` for DeepL.
- **Answer**: return one string per text, in the same order. The runner decodes other entities in the answer, such as `&#39;`, and escapes a bare `&`, `<` or `>`.
