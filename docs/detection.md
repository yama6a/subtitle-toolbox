# Format detection

File extensions do not identify a format. For example, a `.sub` file can be MicroDVD, MPSub or SubViewer. So `Subtitle::parse()` without a parser class reads the start of the content.

```php
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Subtitle;

Subtitle::detectParser(file_get_contents('upload.sub'));        // MicroDvdParser::class, or null for an unknown format
$subtitle = Subtitle::parse(file_get_contents('upload.sub'));   // throws InvalidParserException for an unknown format
```

Detection ignores a UTF-8 BOM and leading blank lines. It checks the signatures in this order and takes the first match:

| Order | Parser | Signature |
|:--- |:--- |:--- |
| 1 | `WebVttParser` | `WEBVTT` |
| 2 | `TtmlParser` | `<tt` after an optional XML declaration, comments and DOCTYPE |
| 3 | `SamiParser` | `<SAMI>` |
| 4 | `AssParser` | `[Script Info]`, for ASS and SSA |
| 5 | `MpSubParser` | a first line such as `TITLE=`, and a `FORMAT=` line |
| 6 | `MicroDvdParser` | `{24}{72}` |
| 7 | `SubRipParser` | `1`, then `00:00:01,000 -->` |
| 8 | `SbvParser` | `0:00:01.500,0:00:04.000` |
| 9 | `SubViewerParser` | `******** START SCRIPT ********`, `[INFORMATION]` or `00:00:01.50,00:00:04.00` |
| 10 | `LyricsParser` | `[ti:Title]` or `[00:12.00]`, and at least one timestamp line |
| 11 | `PgsParser` | the bytes `PG`, then a known segment type |
| 12 | `JsonParser` | an object with a numeric `"version"` key and a `"cues"` list |
| 13 | `EbuStlParser` | a 3-digit code page such as `850`, then `STL25.01` or `STL30.01` |
| 14 | `SccParser` | `Scenarist_SCC V1.0` |
| 15 | `AwsTranscribeParser` | an object with a `"transcripts"` list |
| 16 | `DeepgramParser` | an object with a `"channels"` list of objects, and an `"alternatives"` key after it |
| 17 | `AssemblyAiParser` | an object with an `"audio_url"` key, or a `"words"` list whose first word starts with `"text"` |
| 18 | `GoogleSpeechParser` | an object with a `"results"` list of objects, and an `"alternatives"` list after it |
| 19 | `PodcastTranscriptParser` | an object with a `"segments"` list whose segments have `"startTime"` and `"body"` |
| 20 | `WhisperJsonParser` | an object with a `"segments"` or `"transcription"` list |
| 21 | `YouTubeTimedTextParser` | a `<timedtext>` or `<transcript>` root, or an object with an `"events"` list whose events have `"tStartMs"` |
| 22 | `Mpl2Parser` | `[12][45]` |
| 23 | `TmPlayerParser` | `00:00:01:`, `0:00:01=` or `00:00:01,1=` |
| 24 | `PodcastChaptersParser` | an object with a `"version"` key and a `"chapters"` list |
| 25 | `FfMetadataChaptersParser` | `;FFMETADATA` |
| 26 | `OgmChaptersParser` | `CHAPTER01=00:00:00.000`, then a `CHAPTER01NAME=` line |
| 27 | `HtmlTranscriptParser` | a tag at the start, and a `<cite>` and a `<time>` element |

- **Order**: a format with a more specific signature comes first. A WebVTT file without its `WEBVTT` line looks like SubRip, so it detects as SubRip.
- **`.sub` files**: SBV has three digits after the dot, SubViewer 2 has two.
- **MicroDVD**: detection does not find the frame rate. `parse()` throws `ParsingException` for a MicroDVD file without a `{1}{1}<fps>` first line. Then pass the frame rate: `(new MicroDvdParser(23.976))->parse($content)`.
- **iTT**: an iTT file detects as `TtmlParser`. Pass `IttParser::class` to keep the iTT format data.
- **Not detected**: CSV and TSV, YouTube chapter text and VobSub have no signature. Pass the parser class, see [formats.md](formats.md).
