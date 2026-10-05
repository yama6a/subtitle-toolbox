# Core markup

Cue lines hold HTML-like inline tags, the **core markup**. Parsers convert the styling of their format to this tag set. Formatters convert it back and strip the tags that their format cannot show. [formats.md](formats.md) lists the tags that each format keeps.

| Tag | Meaning |
|:--- |:--- |
| `<b>` | bold |
| `<i>` | italic |
| `<u>` | underline |
| `<s>` | strikethrough |
| `<font color="#ff0000">` | text color |
| `<v Fred>` | speaker, see [text.md](text.md#speakers) |
| `<00:01:02.500>` | word timestamp, the time a word is spoken |

- **Escaping**: text that is not markup keeps `<`, `>` and `&` escaped as `&lt;`, `&gt;` and `&amp;`.
- **Speaker names**: a quote in a name stays a raw character, for example `<v O'Neil>`.

## Helpers
The `Markup` class has the helpers that the parsers and formatters use. They help when you write your own OCR engine, text change, or code that reads or writes a format that the library does not have.

```php
use SubtitleToolbox\Markup;

Markup::stripAllTags('<b>Hi</b> &amp; bye');                  // 'Hi &amp; bye'
Markup::plainText('<b>Hi</b> &amp; bye');                     // 'Hi & bye'
Markup::keepTags('<b>Hi</b> <c.red>you</c>', ['b']);          // '<b>Hi</b> you'
Markup::decodeEntities('Hi &amp; bye');                       // 'Hi & bye'
Markup::escapeText('Fish & Chips');                           // 'Fish &amp; Chips'
Markup::visibleLength('<i>Café</i> &amp; tea ');              // 10, without tags, entities decoded, trimmed
Markup::voiceTag("O'Neil");                                   // "<v O'Neil>"
Markup::insertWordTimestamps('Hi there', [['Hi', 1.0], ['there', 1.4]]);   // '<00:00:01.000>Hi <00:00:01.400>there'
Markup::wordTimestampSeconds('<00:01:02.500>');               // 62.5
Markup::mapWordTimestamps('<00:00:01.000>Hi', fn (float $t): float => $t + 2);   // '<00:00:03.000>Hi'
Markup::mapTextRuns(['<i>Hi</i> you'], fn (string $text): string => strtoupper($text));   // ['<i>HI</i> YOU']
Markup::hasVisibleText(['<i></i>', ' ']);                     // false
```

- **Text runs**: `mapTextRuns()` calls the function for each [text run](text.md#text-runs) and escapes the result again.
- **Speaker tags**: `voiceTag()` escapes `&`, `<` and `>` in the name and keeps quotes.
- **Word timestamps**: `insertWordTimestamps()` escapes the text. It skips a word without a start time or a word that it does not find in the text. `mapWordTimestamps()` and `SubtitleCue::mapWordTimestamps()` change each time and make a negative time 0.
