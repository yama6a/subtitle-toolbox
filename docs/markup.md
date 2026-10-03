# Core markup

Cue lines hold HTML-like inline tags, the **core markup**. Parsers convert the styling of their format to this tag set. Formatters convert it back and strip the tags that their format cannot show. [formats.md](formats.md) lists the tags that each format keeps.

| Tag | Meaning |
|:--- |:--- |
| `<b>` | bold |
| `<i>` | italic |
| `<u>` | underline |
| `<s>` | strikethrough |
| `<font color="#ff0000">` | text colour |
| `<v Fred>` | speaker, see [text.md](text.md#speakers) |
| `<00:01:02.500>` | word timestamp, the time a word is spoken |

- **Escaping**: text that is not markup keeps `<`, `>` and `&` escaped as `&lt;`, `&gt;` and `&amp;`.
- **Speaker names**: a quote in a name stays a raw character, for example `<v O'Neil>`.

## Helpers
The `Markup` class has the helpers that the formatters use:

```php
use SubtitleToolbox\Markup;

Markup::stripAllTags('<b>Hi</b> &amp; bye');            // 'Hi &amp; bye'
Markup::keepTags('<b>Hi</b> <c.red>you</c>', ['b']);    // '<b>Hi</b> you'
Markup::decodeEntities('Hi &amp; bye');                 // 'Hi & bye'
Markup::escapeText('Fish & Chips');                     // 'Fish &amp; Chips'
Markup::visibleLength('<i>Café</i> &amp; tea ');        // 10, without tags, entities decoded, trimmed
Markup::coreTimestamp(62.5);                            // '00:01:02.500', the body of a word timestamp
```
