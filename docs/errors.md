# Errors

Every exception of the library implements `SubtitleToolbox\Exceptions\SubtitleToolboxException`. One `catch` block handles all of them, and lets errors from other code pass.

```php
use SubtitleToolbox\Exceptions\SubtitleToolboxException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

try {
    $vtt = Subtitle::fromString($upload, Format::SubRip)->toString(Format::WebVtt);
} catch (SubtitleToolboxException $e) {
    return response($e->getMessage(), 422);
}
```

| Exception | Extends | `getCode()` | Thrown for |
|:--- |:--- |:--- |:--- |
| `ParsingException` | `\RuntimeException` | 100 | content that a parser or `fromArray()` cannot read, or a byte that is not valid in the source encoding |
| `InvalidFormatterException` | `\RuntimeException` | 101 | `toString()` with a format that the library cannot write, or `save()` with an unknown extension |
| `InvalidParserException` | `\RuntimeException` | 102 | `fromString()` with a format that the library cannot read. An MKV or WebM file in `load()` or `fromString()`. An MKV or WebM file without exactly 1 subtitle track in `loadAutoDetectFormat()` or `fromStringAutoDetectFormat()` |
| `ImageCueWithoutTextException` | `\RuntimeException` | 103 | an image cue without text in `toString()` with a text format |
| `InvalidArgumentException` | `\InvalidArgumentException` | 104 | an invalid argument or option, for example alignment 10, frame rate 0, a missing MicroDVD output frame rate, the options class of another format, or a stored TTML head that is not valid XML |
| `CueNotFoundException` | `\RuntimeException` | 105 | `removeCue()` with an index that has no cue |
| `UnknownFormatException` | `InvalidParserException` | 106 | `loadAutoDetectFormat()` or `fromStringAutoDetectFormat()` when detection finds no format |
| `OcrException` | `\RuntimeException` | 107 | an OCR engine that fails on a valid image: Tesseract exits with an error, the `tesseract` program or its language data is missing, or php-glyph-ocr cannot read the image |

- **SPL classes**: each class extends an SPL class, so `catch (\InvalidArgumentException $e)` and `catch (\RuntimeException $e)` also work.
- **Messages**: `InvalidArgumentException` and `CueNotFoundException` keep the plain message. The other classes start it with the class name and the code, for example `ParsingException (Error #100): `.
- **Previous exception**: every constructor takes the message, then an optional `$previous` exception. `ParsingException` takes the line number before it. `getPrevious()` returns it.
- **Final classes**: every exception class is `final`, except `InvalidParserException`, which `UnknownFormatException` extends.
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
