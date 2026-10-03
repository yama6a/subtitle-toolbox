# Errors

Every exception of the library implements `SubtitleToolbox\Exceptions\SubtitleToolboxException`. One `catch` block handles all of them, and lets errors from other code pass.

```php
use SubtitleToolbox\Exceptions\SubtitleToolboxException;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Subtitle;

try {
    $vtt = Subtitle::parse($upload, SubRipParser::class)->format(WebVttFormatter::class);
} catch (SubtitleToolboxException $e) {
    return response($e->getMessage(), 422);
}
```

| Exception | Extends | `getCode()` | Thrown for |
|:--- |:--- |:--- |:--- |
| `ParsingException` | `\RuntimeException` | 100 | content that a parser or `fromArray()` cannot read, or an unknown source encoding |
| `InvalidFormatterException` | `\RuntimeException` | 101 | a formatter class that is not a `SubtitleFormatter`, or a stored TTML head that is not valid XML |
| `InvalidParserException` | `\RuntimeException` | 102 | a parser class that is not a `SubtitleParser`, or content that format detection does not know |
| `ImageCueWithoutTextException` | `\RuntimeException` | 103 | an image cue without text in `format()` with a text formatter |
| `InvalidArgumentException` | `\InvalidArgumentException` | 104 | an invalid argument or option, for example alignment 10, frame rate 0 or a missing `OPTION_FRAME_RATE` |
| `CueNotFoundException` | `\RuntimeException` | 105 | `removeCue()` with an index that has no cue |

- **SPL classes**: each class extends an SPL class, so `catch (\InvalidArgumentException $e)` and `catch (\RuntimeException $e)` also work.
- **Messages**: the first four classes start the message with the class name and the code, for example `ParsingException (Error #100): `. The last two keep the plain message.
- **Line number**: `ParsingException::getLineNumber()` returns the 1-based input line when the parser knows it, and null otherwise. Then the message ends with ` (line 12)`.

These readers set the line number:

| Reader | Line |
|:--- |:--- |
| ASS and SSA, MicroDVD, MPSub, SubViewer, MPL2, TMPlayer, SCC | the line of the error |
| CSV and TSV | the line where the row starts, or where an unclosed quote opens |
| YouTube XML formats, such as srv3 and transcript XML | the line of the XML element |
| HTML transcripts | the line of the paragraph or time |
| FFmpeg metadata and OGM chapters | the line of the error |
| `OcrReplaceList::fromSubtitleEditXml()` | the line of the XML error |
| `ShotChanges::fromText()`, `SpeechReference::fromFfmpegSilencedetect()` | the line of the error |

[Lenient mode](lenient-parsing.md) skips a broken block in place of throwing.
