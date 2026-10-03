# Format detection

File extensions do not identify a format. For example, a `.sub` file can be MicroDVD, MPSub or SubViewer. So `Format::detect()` and `Subtitle::fromStringAutoDetectFormat()` read the start of the content.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

Format::detect(file_get_contents('upload.sub'));                                    // Format::MicroDvd, or null for an unknown format
$subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents('upload.sub'));  // throws InvalidParserException for an unknown format
Format::fromPath('upload.sub');                                                     // Format::MicroDvd, from the extension only
```

Detection ignores a UTF-8 BOM and leading blank lines. It checks the signatures in this order and takes the first match:

| Order | Format | Signature |
|:--- |:--- |:--- |
| 1 | `WebVtt` | `WEBVTT` |
| 2 | `Ttml` | `<tt` after an optional XML declaration, comments and DOCTYPE |
| 3 | `Sami` | `<SAMI>` |
| 4 | `Ass` | `[Script Info]`, for ASS and SSA |
| 5 | `MpSub` | a first line such as `TITLE=`, and a `FORMAT=` line |
| 6 | `MicroDvd` | `{24}{72}` |
| 7 | `SubRip` | `1`, then `00:00:01,000 -->` |
| 8 | `Sbv` | `0:00:01.500,0:00:04.000` |
| 9 | `SubViewer` | `******** START SCRIPT ********`, `[INFORMATION]` or `00:00:01.50,00:00:04.00` |
| 10 | `Lyrics` | `[ti:Title]` or `[00:12.00]`, and at least one timestamp line |
| 11 | `Pgs` | the bytes `PG`, then a known segment type |
| 12 | `Json` | an object with a numeric `"version"` key and a `"cues"` list |
| 13 | `EbuStl` | a 3-digit code page such as `850`, then `STL25.01` or `STL30.01` |
| 14 | `Scc` | `Scenarist_SCC V1.0` |
| 15 | `PodcastTranscript` | an object with a `"segments"` list whose segments have `"startTime"` and `"body"` |
| 16 | `Whisper` | an object with a `"segments"` or `"transcription"` list |
| 17 | `YouTube` | a `<timedtext>` or `<transcript>` root, or an object with an `"events"` list whose events have `"tStartMs"` |
| 18 | `Mpl2` | `[12][45]` |
| 19 | `TmPlayer` | `00:00:01:`, `0:00:01=` or `00:00:01,1=` |
| 20 | `HtmlTranscript` | a tag at the start, and a `<cite>` and a `<time>` element |

- **Order**: a format with a more specific signature comes first. A WebVTT file without its `WEBVTT` line looks like SubRip, so it detects as SubRip.
- **`.sub` files**: SBV has three digits after the dot, SubViewer 2 has two.
- **MicroDVD**: detection does not find the frame rate. `fromStringAutoDetectFormat()` throws `ParsingException` for a MicroDVD file without a `{1}{1}<fps>` first line. Then pass the frame rate: `Subtitle::fromString($content, Format::MicroDvd, new ReadOptions(fps: 23.976))`.
- **iTT**: an iTT file detects as `Format::Ttml`. Pass `Format::Itt` to keep the iTT format data.
- **No signature**: CSV and TSV. Pass `Format::Csv` or `Format::Tsv`, see [formats.md](formats.md#csv-and-tsv). VobSub needs its `.idx` file, see [ocr.md](ocr.md#vobsub).
- **Not detected**: chapters and cloud speech-to-text JSON look like other formats. `Format::detect()` returns null for them, and `isAutoDetected()` is false. Pass the format, for example `Subtitle::fromString($json, Format::Deepgram)`. `Format::fromPath()` still finds them by their extension, for example `.ffmeta`.
