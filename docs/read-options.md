# Read options

`ReadOptions` holds the settings of one read. The `load` and `fromString` functions of `Subtitle` take it, and so does `SubtitleParser::parse()`. A parser ignores the fields it does not use.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\CsvReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('movie.sub'), Format::MicroDvd,
    new ReadOptions(encoding: 'Windows-1252', lenient: true, fps: 23.976));
$warnings = $subtitle->getParseWarnings();

$table = Subtitle::fromString(file_get_contents('lines.csv'), Format::Csv,
    new ReadOptions(format: new CsvReadOptions(delimiter: ';')));
```

| Field | Default | Used by |
|:--- |:--- |:--- |
| `encoding` | null, UTF-8 | text formats. A UTF-16 or UTF-32 BOM wins. See [encodings.md](encodings.md) |
| `lenient` | false | skips or repairs a broken block and records a warning, see [lenient-parsing.md](lenient-parsing.md) |
| `fps` | null | MicroDVD. It wins over a `{1}{1}<fps>` first line |
| `wordTimestamps` | false | Whisper, YouTube timed text, Podcasting 2.0 transcripts, cloud speech JSON, see [transcripts.md](transcripts.md) |
| `speakerVoices` | false | Whisper and cloud speech JSON, see [text.md](text.md#speakers) |
| `lastCueDuration` | 5.0 | seconds that a last cue without an end lasts |
| `track` | null | VobSub track index |
| `language` | null | VobSub language id, SAMI language class |
| `format` | null | one per-format class, see below |

- **Checks**: the constructor throws `InvalidArgumentException` for an unknown encoding, a frame rate of 0 or less, a negative `lastCueDuration`, a negative `track` and an empty `language`.
- **Last cue**: every format ends a last cue without an end `lastCueDuration` after its start. These readers use it: TMPlayer, SubViewer 1, LRC and SAMI. CSV and TSV rows without an end time use it. So do HTML and Podcasting 2.0 transcripts, and SCC captions that no command erases. PGS display sets, MKV text blocks without a duration and VobSub subtitles without a stop command use it too. A VobSub subtitle without a stop command also ends where the next starts.
- **Warnings**: `Subtitle::getParseWarnings()` returns the warnings of a lenient read. A subtitle that no parser read has none.

## Per-format classes

`ReadOptions::$format` takes the settings that only one format has. A parser throws `InvalidArgumentException` for the class of another format, for example `CsvReadOptions` on a SubRip read.

| Class | Formats | Fields |
|:--- |:--- |:--- |
| `CsvReadOptions` | CSV, TSV | `columns`: a `CsvColumns` layout, null reads the header names. `delimiter`: `,`, `;` or a tab, null detects it |
| `SccReadOptions` | SCC | `channel`: 1 reads CC1 and CC3, 2 reads CC2 and CC4 |
| `EbuStlReadOptions` | EBU STL | `subtractStartOfProgramme`: subtracts the TCP time code from every cue time |
| `PodcastTranscriptReadOptions` | Podcasting 2.0 transcript | `keepSegments`: one cue per segment, also for a segment with one word |
| `ChapterReadOptions` | YouTube, Podcasting 2.0, FFmpeg and OGM chapters | `mediaDuration`: seconds where the last chapter ends. Null ends it at its own start |
| `VobSubReadOptions` | VobSub | `idx`: the content of the `.idx` file. The parser reads the `.sub` content |
