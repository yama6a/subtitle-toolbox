# Encodings and line endings

The library works in UTF-8. The `load` and `fromString` functions of `Subtitle` convert other encodings on input.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\TextEncoding;

$subtitle = Subtitle::fromString(file_get_contents('movie.srt'), Format::SubRip, new ReadOptions(encoding: TextEncoding::Windows1252));
$subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents('movie.smi'), new ReadOptions(encoding: TextEncoding::Cp949));
$subtitle = Subtitle::fromString(file_get_contents('ukrainian.srt'), Format::SubRip, new ReadOptions(encoding: 'CP1125'));
StringHelpers::isValidUtf8(file_get_contents('movie.srt'));   // false for a Windows-1252 file with letters such as é
```

- **BOM**: the library converts UTF-16 and UTF-32 with a BOM to UTF-8 without being asked. A BOM wins over `ReadOptions::$encoding`.
- **No BOM, no encoding**: the parsers read the bytes as UTF-8 and keep invalid bytes. SAMI throws `ParsingException` for text that is not UTF-8.
- **JSON formats**: the JSON parsers read each invalid UTF-8 byte as U+FFFD, the replacement character. For example, the bytes `42 FF 64` in a text field give `B`, U+FFFD and `d`.
- **Parsers called directly**: only the `Subtitle` functions convert. Before `(new SamiParser())->parse($content, new ReadOptions())`, call `StringHelpers::convertToUtf8($content, TextEncoding::Cp949)`.
- **Source encodings**: `ReadOptions::$encoding` and `StringHelpers::convertToUtf8()` take a `TextEncoding` case or a string. The conversion uses the PHP extension iconv. A string can be any name that the iconv of the system knows, for example `CP1125`. `new ReadOptions()` throws `InvalidArgumentException` for an unknown name. A byte that is invalid in the encoding throws `ParsingException`.
- **Stored value**: `ReadOptions::$encoding` holds the iconv name as a string. `new ReadOptions(encoding: TextEncoding::Windows1252)` stores `Windows-1252`.
- **EBU STL**: the binary file holds its own character tables. Pass its bytes without a source encoding.
- **Output**: line endings and the UTF-8 BOM of the output are formatter options, see [formats.md](formats.md#write-options).

## TextEncoding cases

Each case value is the iconv name.

| Cases | Values |
|:--- |:--- |
| `Utf8`, `Utf16Le`, `Utf16Be` | `UTF-8`, `UTF-16LE`, `UTF-16BE` |
| `Windows1250` to `Windows1258` | `Windows-1250` to `Windows-1258` |
| `Iso8859_1` to `Iso8859_16`, without `Iso8859_12` | `ISO-8859-1` to `ISO-8859-16`, without `ISO-8859-12` |
| `Koi8R`, `Koi8U` | `KOI8-R`, `KOI8-U` |
| `ShiftJis`, `EucJp` | `Shift_JIS`, `EUC-JP` |
| `Gbk`, `Gb18030`, `Big5` | `GBK`, `GB18030`, `Big5` |
| `EucKr`, `Cp949` | `EUC-KR`, `CP949` |
| `Cp437`, `Cp850`, `Cp866` | `CP437`, `CP850`, `CP866` |

ISO-8859-12 has no case because no such standard exists and iconv rejects the name.
