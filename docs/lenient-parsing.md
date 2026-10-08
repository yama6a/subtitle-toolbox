# Lenient parsing

A subtitle download is often broken in one place. By default, the parsers throw `ParsingException` at the first broken block. In lenient mode, the parser skips or repairs the broken block, records a `ParseWarning` and goes on.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString($download, Format::SubRip, new ReadOptions(lenient: true));
foreach ($subtitle->getParseWarnings() as $warning) {
    $line = $warning->lineNumber ?? '-';   // lineNumber is null for EBU STL and JSON
    $logger->warning("line $line: $warning->message ({$warning->action->value})");
}
// line 5: Block #1 has no timing line on its second line. (skipped)
```

## SubRip, WebVTT and SBV
| Damage | SubRip | WebVTT | SBV |
|:--- |:--- |:--- |:--- |
| cue without a cue number | repaired | not an error | not an error |
| bad timestamp, `->` arrow | skipped | skipped | skipped |
| no empty line between two cues | repaired | split as the spec says, no warning | repaired |
| no empty line after the `WEBVTT` header | not an error | repaired | not an error |
| text before the first cue | skipped | skipped | skipped |
| truncated last cue | skipped | skipped | skipped |

- **Cue without text**: a timing line without text lines gives a cue with no lines, in strict and lenient mode. WebVTT allows an empty cue. To drop these cues, call `$subtitle->removeCuesWhere(fn (SubtitleCue $cue): bool => $cue->getLines() === [])`.

## Other formats
| Parser | Skipped with a warning | `blockIndex` counts |
|:--- |:--- |:--- |
| ASS, SSA | a `Dialogue:` or `Comment:` line with too few fields or a bad time. A file without a `Format:` line is not an error. The parser then uses the default fields | events |
| MicroDVD | a line without `{start}{end}` frames, also before the `{1}{1}<fps>` line | non-empty lines |
| MPL2 | a line without `[start][end]` | non-empty lines |
| TMPlayer | a line without a time | non-empty lines |
| SubViewer 1 | a bad header line | cues |
| SubViewer 2 | a timing line with one bad time and its text, and text before the first cue | cues |
| MPSub | a bad wait and duration pair and its text, a cue without text, a bad `FORMAT=` value. A file without `FORMAT=` gets a `repaired` warning, and the parser reads the times as seconds | cues |
| LRC | a line with a time tag that the parser cannot read, for example `[01:2x.00]` | non-empty lines |
| SAMI | a `<SYNC>` tag without a valid `Start` | `<SYNC>` tags |
| TTML, iTT | a `<p>` with a bad time or without an end time | `<p>` elements |
| EBU STL | a subtitle with a time code out of range, a cut-off last TTI block | TTI blocks |
| CSV, TSV | a row with a bad time | rows after the header, without empty rows |
| JSON | a cue with a bad field. The parser also drops a bad metadata field, a bad comment and the bad format data of one format. Their warnings have `blockIndex` null | cues |
| Whisper JSON | a segment without `start`, `end` or `text`, or with a time that is negative or not a finite number | segments |
| YouTube timed text | an event or element with a bad or negative time, for example `"tStartMs": -5000` | events or elements |
| Amazon Transcribe, Deepgram, AssemblyAI, Google | a word, segment, utterance, sentence or result with a bad or negative time or a bad text. Also a Google result whose `alternatives` is not a list of objects | the index in its own list. Each list starts at 0, for example the sentences of each Deepgram paragraph. So 2 warnings can have the same `blockIndex` |
| Podcasting 2.0 transcript JSON | a segment with a bad field, for example `"startTime": -5` | segments |
| HTML transcript | a paragraph with a bad time or without a `<time>` | the paragraphs that each `<cite>` or `<time>` starts |

- **Ignored**: the SCC, PGS and VobSub parsers and the chapter parsers ignore `ReadOptions::$lenient` and always throw. For example, the Podcasting 2.0 chapters parser throws for `"startTime": -5`.
- **`ParseWarning`**: see [ParseWarning fields](#parsewarning-fields). A skipped block reports its first line. A repair reports the line where the parser split or read the cue.
- **No line numbers**: binary EBU STL and the JSON formats have no line numbers, so their warnings have `lineNumber` null. The YouTube XML formats report the line of the XML element.
- **Warnings**: `Subtitle::getParseWarnings()` returns the warnings of the read that made the subtitle.
- **Not the format**: lenient mode still throws for a WebVTT file without `WEBVTT`. SubRip and SBV have no signature, so a file without one readable cue gives no cues and warnings.
- **Whole-file errors**: lenient mode still throws for a problem outside one cue. Examples:
  - Invalid XML in TTML, or invalid JSON.
  - A Whisper `segments` or YouTube `events` field that is an object, not a list.
  - A SAMI file that is not UTF-8.
  - An ASS file without `[Events]`.
  - A MicroDVD file without a frame rate.
- **Strict mode without an exception**: the LRC parser drops a line with a bad time tag. The EBU STL parser reads a time code out of range as it is. In lenient mode, both record a warning, and the EBU STL parser also skips the subtitle.
- **Stream readers**: `SubRipStreamReader` and `WebVttStreamReader` take `ReadOptions(lenient: true)` in the constructor and have `getWarnings()`. They give the same cues and warnings as a lenient `Subtitle::fromString()`.
- **Command line tool**: `--lenient` turns on lenient mode and prints each warning to standard error.

## ParseWarning fields
| Field | Content |
|:--- |:--- |
| `message` | the message of the error |
| `lineNumber` | the 1-based line of the block, or null |
| `blockIndex` | the 0-based index of the block. Null for a library JSON field outside the cues |
| `block` | the trimmed lines of the block |
| `action` | `ParseWarningAction::Skipped` or `ParseWarningAction::Repaired` |
