# Read options

`ReadOptions` holds the format-neutral settings of one read. The `load` and `fromString` functions of `Subtitle` take it, and so does `SubtitleParser::parse()`. A parser ignores the fields it does not use.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('movie.sub'), Format::MicroDvd,
    new ReadOptions(encoding: 'Windows-1252', lenient: true, format: new MicroDvdReadOptions(frameRate: 23.976)));
$warnings = $subtitle->getParseWarnings();

$table = Subtitle::fromString(file_get_contents('lines.csv'), Format::Csv,
    new ReadOptions(format: new CsvReadOptions(delimiter: ';')));
```

| Field | Default | Used by |
|:--- |:--- |:--- |
| `encoding` | null, UTF-8 | text formats. A UTF-16 or UTF-32 BOM wins. See [encodings.md](encodings.md) |
| `lenient` | false | skips or repairs a broken block and records a warning, see [lenient-parsing.md](lenient-parsing.md) |
| `lastCueDuration` | 5.0 | seconds that a last cue without an end lasts |
| `format` | null | one per-format class, see below |

- **Checks**: the constructor throws `InvalidArgumentException` for an unknown encoding and a negative `lastCueDuration`. The per-format classes throw it for a frame rate of 0 or less, a negative `track` and an empty `language`.
- **Last cue**: every format ends a last cue without an end `lastCueDuration` after its start. These readers use it: TMPlayer, SubViewer 1, LRC and SAMI. CSV and TSV rows without an end time use it. So do HTML and Podcasting 2.0 transcripts, and SCC captions that no command erases. PGS display sets, MKV text blocks without a duration and VobSub subtitles without a stop command use it too. A VobSub subtitle without a stop command also ends where the next starts.
- **Warnings**: `Subtitle::getParseWarnings()` returns the warnings of a lenient read. A subtitle that no parser read has none.

## Per-format classes

`ReadOptions::$format` takes the settings that only one format has. The classes are in `SubtitleToolbox\Parsers\Options`. A parser throws `InvalidArgumentException` for the class of another format, for example `CsvReadOptions` on a SubRip read.

| Class | Formats | Fields |
|:--- |:--- |:--- |
| `CsvReadOptions` | CSV, TSV | `columns`: a `CsvColumns` layout, null reads the header names. `delimiter`: `,`, `;` or a tab, null detects it. `frameRate`: the frames per second of times in `hh:mm:ss:ff` |
| `SccReadOptions` | SCC | `channel`: 1 reads CC1 and CC3, 2 reads CC2 and CC4 |
| `EbuStlReadOptions` | EBU STL | `subtractStartOfProgramme`: subtracts the TCP time code from every cue time |
| `TranscriptReadOptions` | Whisper, cloud speech JSON, YouTube timed text, Podcasting 2.0 transcript | `wordTimestamps`: word times as core markup, see [transcripts.md](transcripts.md). `speakerVoices`: speakers as voice tags, Whisper and cloud speech JSON only, see [text.md](text.md#speakers). `keepSegments`: Podcasting 2.0 only, one cue per segment, also for a segment with one word |
| `ChapterReadOptions` | YouTube, Podcasting 2.0, FFmpeg and OGM chapters | `mediaDuration`: seconds where the last chapter ends. Null ends it at its own start |
| `MicroDvdReadOptions` | MicroDVD | `frameRate`: frames per second. It wins over a `{1}{1}<fps>` first line |
| `SamiReadOptions` | SAMI | `language`: the language class to read, such as `FRCC`. Null reads the first class of the STYLE block |
| `VobSubReadOptions` | VobSub | `idx`: the content of the `.idx` file. The parser reads the `.sub` content. `Subtitle::load()` fills it from the `.idx` file. `track`: the track with this `index:`. `language`: the track with this `id:`. Without both, the parser reads the first track |
