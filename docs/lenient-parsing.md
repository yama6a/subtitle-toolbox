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
| bad timestamp | skipped | skipped | skipped |
| `->` or `--->` arrow | repaired | skipped | no arrow in the format |
| full-width `：`, `，`, `．` or `。` in a timing line | repaired | skipped | skipped |
| SBV timing line with `.` between the times, `,` or `:` before the fraction, or 1, 2 or 4 fraction digits | does not apply | does not apply | repaired |
| unknown text after the end time | repaired | dropped as the spec says, no warning | skipped |
| no empty line between two cues | repaired | split as the spec says, no warning | repaired |
| empty line inside the cue text, or between the timing line and the text | repaired | skipped | repaired |
| no empty line after the `WEBVTT` header | not an error | repaired | not an error |
| no `WEBVTT` line, a damaged one, or text before it | not an error | repaired | not an error |
| text before the first cue | skipped | skipped | skipped |
| truncated last cue | skipped | skipped | skipped |

- **No empty line between two cues**: SubRip and SBV split the block before each timing line in strict mode too, without a warning. A SubRip cue without a cue number still throws in strict mode.
- **No `WEBVTT` line**: in lenient mode, the WebVTT parser skips the lines before the first line that starts with `WEBVTT`. Without such a line before the first cue, it skips the lines before the first cue. The skipped lines go into the `block` of the warning. A damaged `WEBVTT` line also loses the header text and `STYLE` and `REGION` blocks before the first cue.
- **Empty line inside a cue**: SubRip and SBV add a block without a timing line to the cue before it, in strict mode too. Lenient mode warns. The block stays apart and the parser skips it when it follows no cue, starts with a time, or starts with a number in SubRip.
- **Cue without text**: a timing line without text lines gives a cue with no lines, in strict and lenient mode. WebVTT allows an empty cue. To drop these cues, call `$subtitle->removeCuesWhere(fn (SubtitleCue $cue): bool => $cue->getLines() === [])`.

## Other formats
| Parser | Skipped with a warning | `blockIndex` counts |
|:--- |:--- |:--- |
| ASS, SSA | a `Dialogue:` or `Comment:` line with too few fields or a bad time. A file without a `Format:` line is not an error. The parser then uses the default fields. A time without a fraction, with 4 fraction digits, or with `,` or `:` before the fraction gets a `repaired` warning, for example `0:00:01`, `0:00:01.5000` or `0:00:01,50`. The parser rounds it to milliseconds | events |
| MicroDVD | a line without `{start}{end}` frames, also before the `{1}{1}<fps>` line | non-empty lines |
| MPL2 | a line without `[start][end]` | non-empty lines |
| TMPlayer | a line without a time | non-empty lines |
| SubViewer 1 | a bad header line | cues |
| SubViewer 2 | a timing line with one bad time and its text, and text before the first cue. A timing line without fractions, with 4 fraction digits or with 1-digit fields gets a `repaired` warning, for example `0:0:1.50,0:0:2.0000`. The parser rounds it to milliseconds. Strict mode reads such a line as cue text | cues |
| MPSub | a bad wait and duration pair and its text, a cue without text, a bad `FORMAT=` value. A file without `FORMAT=` gets a `repaired` warning, and the parser reads the times as seconds | cues |
| LRC | a line with a time tag that the parser cannot read, for example `[01:2x.00]` | non-empty lines |
| SAMI | a `<SYNC>` tag without a valid `Start`. A negative `Start` gets a `repaired` warning, and the parser reads it as 0. A file whose `<P>` classes are all missing from the STYLE block gets a `repaired` warning with `blockIndex` null | `<SYNC>` tags |
| TTML, iTT | a `<p>` with a bad time or without an end time | `<p>` elements |
| EBU STL | a subtitle with a time code out of range, a cut-off last TTI block | TTI blocks |
| CSV, TSV | a row with a bad time. The rows before the header row, with 1 warning that has `blockIndex` null. A quoted cell without a closing quote gets a `repaired` warning, and the cell ends at the end of its line | rows after the header, without empty rows |
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
- **Not the format**: lenient mode still throws for a WebVTT file without `WEBVTT` when the first timing line is not a WebVTT timing line, for example `00:00:01,000 --> 00:00:02,000`. Autodetection still needs the `WEBVTT` line. SubRip and SBV have no signature, so a file without one readable cue gives no cues and warnings.
- **Whole-file errors**: lenient mode still throws for a problem outside one cue. Examples:
  - Invalid XML in TTML, or invalid JSON.
  - A Whisper `segments` or YouTube `events` field that is an object, not a list.
  - A SAMI file that is not UTF-8.
  - An ASS file without `[Events]`.
  - A MicroDVD file without a frame rate.
- **Text before the XML**: the TTML, iTT and YouTube XML parsers skip white space before the XML in both modes. Other text before the XML declaration or the root element throws in strict mode. Lenient mode skips it with a `repaired` warning that has `blockIndex` null, for example for a `Subtitles by ...` line.
- **HTML entities in TTML**: XML defines only `&amp;`, `&lt;`, `&gt;`, `&quot;` and `&apos;`. Strict mode throws for an HTML entity such as `&eacute;` or `&nbsp;`. Lenient mode reads each HTML5 named entity as its character and adds one `repaired` warning. The parser never loads a DTD or an external entity.
- **Malformed XML in TTML**: strict mode throws. Lenient mode keeps a bare `&` and an unknown entity such as `&foo;` as text, and reads `<br>` as `<br/>`. libxml then repairs the rest, for example a missing end tag. The parser adds one `repaired` warning with the first libxml error and its line. A file that gives no `<tt>` root still throws.
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
