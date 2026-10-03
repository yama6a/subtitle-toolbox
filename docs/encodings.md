# Encodings and line endings

The library works in UTF-8. `Subtitle::fromString()` and `Subtitle::fromStringAutoDetectFormat()` convert other encodings on input.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('movie.srt'), Format::SubRip, 'Windows-1252');
$subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents('movie.smi'), 'CP949');
StringHelpers::isValidUtf8(file_get_contents('movie.srt'));   // false for a Windows-1252 file with letters such as é
```

- **BOM**: the library converts UTF-16 and UTF-32 with a BOM to UTF-8 without being asked. A BOM wins over the source encoding argument.
- **No BOM, no argument**: the parsers read the bytes as UTF-8 and keep invalid bytes. SAMI and JSON throw `ParsingException` for text that is not UTF-8.
- **Parsers called directly**: only the `Subtitle` functions convert. Before `(new SamiParser())->parse($content)`, call `StringHelpers::convertToUtf8($content, 'CP949')`.
- **Source encodings**: the conversion uses the PHP extension iconv. It accepts the names that the iconv of the system knows, for example `Windows-1251`, `ISO-8859-15`, `Shift_JIS` or `EUC-KR`. An unknown name or a byte that is invalid in the encoding throws `ParsingException`.
- **EBU STL**: the binary file holds its own character tables. Pass its bytes without a source encoding.
- **Output**: line endings and the UTF-8 BOM of the output are formatter options, see [formats.md](formats.md#options-for-all-formatters).
