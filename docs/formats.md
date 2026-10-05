# Subtitle formats

The [main README](../README.md#supported-formats) lists every format with its extensions. This page holds the details of each text subtitle format. Other formats have their own pages:

- Bitmap formats PGS and VobSub: [ocr.md](ocr.md)
- Speech-to-text JSON, YouTube timed text, podcast transcripts and plain text: [transcripts.md](transcripts.md)
- Chapter lists: [chapters.md](chapters.md)
- The JSON of this library: [json.md](json.md)
- Subtitle tracks in MKV and WebM files: [mkv.md](mkv.md)

## The Format enum
The enum `Format` names each format. Its value is the format name of the command line tool.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString($content, Format::MicroDvd);
$subtitle = Subtitle::fromStringAutoDetectFormat($content);   // see detection.md
$vtt      = $subtitle->toString(Format::WebVtt);

Format::from('srt');                          // Format::SubRip, for a name from user input
Format::fromPath('movie.sub');                // Format::MicroDvd
Format::Ass->extensions();                    // ['ass', 'ssa'], the first one for new files
Format::Whisper->canWrite();                  // false
Format::PlainText->canRead();                 // false
Format::FfMetadataChapters->isAutoDetected(); // false
```

- **Shared extensions**: when two formats share an extension, the earlier case owns it. So `fromPath()` returns `Format::MicroDvd` for `.sub`, `Format::Json` for `.json` and `Format::PlainText` for `.txt`.
- **Parser and formatter classes**: the classes in `Parsers` and `Formatters` are public. `ReadOptions` holds the parser settings, for example `new ReadOptions(lenient: true)`. A class in `Parsers\Options` holds the settings of one format, for example `new MicroDvdReadOptions(frameRate: 23.976)`. See [read-options.md](read-options.md).

## Load and save
```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

Subtitle::load('movie.srt', Format::SubRip)->save('movie.vtt');
Subtitle::loadAutoDetectFormat('movie.srt')->save('movie.vtt');
Subtitle::load('movie.sub', Format::MicroDvd, new ReadOptions(encoding: 'Windows-1252', format: new MicroDvdReadOptions(frameRate: 23.976)))->save('movie.srt');
Subtitle::load('movie.idx', Format::VobSub, new ReadOptions(format: new VobSubReadOptions(language: 'de')));
Subtitle::load('call.json', Format::Deepgram, new ReadOptions(format: new TranscriptReadOptions(speakerVoices: true)));
Subtitle::loadTrack('/media/movie.mkv', 3);           // see mkv.md

$subtitle = Subtitle::load('movie.srt', Format::SubRip);
$subtitle->getFormat();                               // Format::SubRip, the format that the load call read
$subtitle->save('movie.txt', Format::WebVtt);         // the format argument wins over the extension
```

- **`load()`**: reads the file in the given format and never guesses. For VobSub, pass the `.idx` or the `.sub` file. The other file must lie next to it. A path without extension, such as `d/movie`, is the `.sub` file, and `d/movie.idx` must exist. Else `load()` throws `InvalidArgumentException`.
- **`loadAutoDetectFormat()`**: tries only formats whose `isAutoDetected()` is true. It reads the format that [detection](detection.md) finds in the content. An iTT file with the `.itt` extension reads as iTT, not TTML.
- **Extension fallback**: when detection finds nothing, `loadAutoDetectFormat()` takes the format of the extension, for example `.tsv`. It skips an extension that a format without detection also uses, such as `.json` and `.txt`. Then it throws `UnknownFormatException`.
- **Chapters and cloud speech-to-text JSON**: they load only with `load()` and their format.
- **MKV and WebM**: `load()` throws for them. `loadAutoDetectFormat()` reads a file with exactly 1 subtitle track and throws with the track list for other files.
- **`getFormat()`**: null for a subtitle from `new Subtitle()` or `fromArray()`. For an MKV track, it is the format of the codec, for example `Format::SubRip`.
- **`save()`**: writes the format argument, else the format of the extension. It throws `InvalidFormatterException` for an unknown extension.
- **Frame rate**: MicroDVD output takes the frame rate from `MicroDvdWriteOptions::$frameRate`, else from a MicroDVD input. iTT output takes it from `IttWriteOptions::$frameRate`, else from an iTT input. Else `toString()` and `save()` throw `InvalidArgumentException`.
- **CSV and TSV**: TSV output has tabs. CSV output from a TSV input has commas. A `CsvWriteOptions::$delimiter` wins.

## Write options
`WriteOptions` holds the settings that every formatter reads. Its `format` field takes the options class of one format, such as `MicroDvdWriteOptions`.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\WriteOptions;

$subtitle->toString(Format::SubRip, new WriteOptions(
    lineEnding: LineEnding::Crlf,    // LineEnding::Lf (default) or LineEnding::Crlf
    bom: false,                      // true adds a UTF-8 BOM, false removes it, null (default) keeps the rule of the format
    stripTags: true,                 // no tags in the output
    skipImageCues: true,             // drops image cues without text
));
$subtitle->toString(Format::MicroDvd, new WriteOptions(format: new MicroDvdWriteOptions(frameRate: 23.976)));
```

| Options class | Format | Fields |
|:--- |:--- |:--- |
| `AssWriteOptions` | ASS | `karaokeTag` |
| `CsvWriteOptions` | CSV, TSV | `delimiter`, `timeFormat`, `frameRate`, `secondText`, `secondTextHeader`, `escapeFormulas` |
| `EbuStlWriteOptions` | EBU STL | `frameRate` |
| `HtmlTranscriptWriteOptions` | HTML transcript | `paragraphGap` |
| `IttWriteOptions` | iTT | `frameRate` |
| `JsonWriteOptions` | JSON | `prettyPrint`, `withFormatData` |
| `MicroDvdWriteOptions` | MicroDVD | `frameRate`, `writeFrameRateLine` |
| `MpSubWriteOptions` | MPSub | `frameRate` |
| `PlainTextWriteOptions` | plain text | `joinLines`, `joinCues`, `paragraphGap`, `withTimes` |
| `PodcastTranscriptWriteOptions` | Podcasting 2.0 transcript | `wordSegments`, `prettyPrint` |
| `SccWriteOptions` | SCC | `dropFrame` |
| `SubViewerWriteOptions` | SubViewer | `version` |

- **Line endings**: every formatter writes LF by default.
- **BOM**: ASS, CSV, TSV, LRC, MPSub, SubRip and WebVTT write a UTF-8 BOM by default. The other formatters do not.
- **Strip all tags**: ASS, EBU STL, iTT, MicroDVD, SAMI, SubRip, TTML and WebVTT read `stripTags`.
- **Image cues**: see [ocr.md](ocr.md#image-cues).
- **Precedence**: a field that you set wins over the format data of the subtitle. For example, `IttWriteOptions(frameRate: 25)` wins over the frame rate that `IttParser` stored. A field left at `null` takes the stored value.
- **Frame rate**: every `frameRate` field is a `float`. Each format checks the values it can write, for example 25 or 30 for EBU STL.
- **Errors**: an options class of another format throws `InvalidArgumentException`, for example `CsvWriteOptions` for SubRip. Each options class checks its values when you create it, so `new CsvWriteOptions(delimiter: '|')` throws at once.

## ASS and SSA
`AssParser` reads ASS v4.00+ and SSA v4.00. `AssFormatter` writes the version that the parser read, or ASS for cues from other formats.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\AssKaraokeTag;
use SubtitleToolbox\Formatters\Options\AssWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString(file_get_contents('episode.ass'), Format::Ass);
$subtitle->findFormatData('ass')['scriptInfo']['PlayResX'];     // '1920'
$subtitle->getCues()[0]->findFormatData('ass')['fields'];       // ['Layer' => '0', 'Style' => 'Default', ...]
$subtitle->toString(Format::Ass);
$subtitle->toString(Format::Ass, new WriteOptions(format: new AssWriteOptions(karaokeTag: AssKaraokeTag::Fill)));   // \kf
```

| Input | Parser result | Formatter output |
|:--- |:--- |:--- |
| `{\b1}`, `{\i1}`, `{\u1}`, `{\s1}`, their `0` forms and `\r` | `<b>`, `<i>`, `<u>`, `<s>` and their closing tags | the same override tags |
| `{\c&H0000FF&}` or `{\1c&H0000FF&}`, color as BGR | `<font color="#ff0000">` | `{\c&H0000FF&}`, `{\c}` at `</font>` |
| `{\an8}`, SSA `{\a6}` | alignment 8. The first tag wins. | `{\an8}` in ASS, `{\a6}` in SSA. Nothing for `null`. |
| Name field `Fred` | `<v Fred>` at the start of the first line | the Name field, only the first speaker of a cue |
| `{\k50}`, `{\kf50}`, `{\K50}`, `{\ko50}` in centiseconds | a word timestamp at the start time of each syllable | `{\k}`, or the tag of `AssWriteOptions::$karaokeTag`. The last syllable lasts until the cue end. |
| `\N`, `\h` | a new line, U+00A0 | `\N`, `\h` |
| `\n` | a space, or a new line with `WrapStyle: 2` | `\N` |
| `Comment:` event, `Title:` | `getComments()`, the `title` metadata | the stored `Comment:` event with the same text, `Title:` |

- **Format data**: ASS and SSA both use the key `ass`. The subtitle keeps `[Script Info]`, the styles, the `Format:` lines, the section order, `Comment:` events and other sections such as `[Fonts]` and `[Graphics]`. Each cue keeps its event fields and its original `Text` field.
- **Unchanged cues**: when the lines and the alignment of a cue are the same as after parsing, the formatter writes the original `Text` field. So tags such as `\pos`, `\fad` and `\t` survive an ASS round trip and a retiming.
- **Changed cues**: the formatter writes the text from the core markup. Other override tags are lost.
- **Karaoke tags**: `AssKaraokeTag::Instant` writes `\k`, the default. `AssKaraokeTag::Fill` writes `\kf`, `AssKaraokeTag::Outline` writes `\ko`. `\kf` fills each syllable from left to right in Aegisub and libass. `\ko` hides the outline of a syllable until its time starts. The option applies only to cues that the formatter writes from the core markup.
- **Limits**: other override tags, `{...}` notes and `\p1` drawings are not cue text. An event that holds only a drawing becomes a cue without lines. Style definitions do not change the core markup. Events come out in time order.
- **Output**: times in centiseconds. A cue from another format gets style `Default`. A subtitle from another format gets the minimal header that FFmpeg writes.

## CSV and TSV
Translators, reviewers and dubbing studios work in Excel or Google Sheets. `CsvParser` and `CsvFormatter` read and write subtitles as CSV and TSV tables, with RFC 4180 quoting.

```csv
start,end,speaker,text
00:00:01.000,00:00:04.000,Anna,Where are you going?
00:00:04.500,00:00:06.000,Ben,"Home.
Now."
```

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\Parsers\Options\CsvColumns;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString(file_get_contents('movie.csv'), Format::Csv);   // headers start, end, text, ...
$columns  = new CsvColumns(start: 'Start TC', text: 'Text', speaker: 'Character');
$subtitle = Subtitle::fromString(file_get_contents('dubbing-script.csv'), Format::Csv, new ReadOptions(format: new CsvReadOptions($columns, frameRate: 25)));
$subtitle = Subtitle::fromString($tsv, Format::Tsv, new ReadOptions(format: new CsvReadOptions(new CsvColumns(start: 0, end: 1, text: 2, header: false), "\t")));

$csv = $english->toString(Format::Csv, new WriteOptions(format: new CsvWriteOptions(
    delimiter: ';',                  // Excel in German and French locales
    secondText: $german,             // a second text column, aligned by time
    secondTextHeader: 'text (de)',   // default 'text2'
)));
```

| Column role | Parser | Formatter |
|:--- |:--- |:--- |
| `start`, `end` | the cue times | the cue times |
| `duration` | the end is start plus duration | the end minus the start |
| `text` | the cue lines, one per line break in the cell | the text without tags and entities, lines joined by a line break |
| `speaker` | `<v Name>` at the start of the first line | the name of the leading `<v>` tag |
| `identifier` | the cue identifier | the cue identifier |
| any other column | `findFormatData('csv')['columns']` of the cue, by header name | the same cell |

- **Column mapping**: `CsvColumns` maps each role to a header name or to a 0-based column index. Header names match without case. A role without a mapping uses the header with its own name, such as `start`, when the table has one.
- **Required columns**: a table without `start` or `text` throws `ParsingException`. So does a mapped header that the table lacks. `header: false` needs a column index for each mapped role, and at least for `start` and `text`.
- **Delimiter**: `,`, `;` or a tab. Without `CsvReadOptions::$delimiter`, the parser takes the one that occurs most often in the first line. A comma wins a tie.
- **Times**: seconds such as `62.5`, `00:01:02.500`, `00:01:02,500`, and `00:01:02:12` with frames. Frames need `frameRate`.
- **Output times**: the formatter writes the format of the first parsed start time, else `hh:mm:ss.mmm`. `CsvWriteOptions::$timeFormat` takes a case of the enum `CsvTimeFormat`, for example `CsvTimeFormat::Comma`. `CsvTimeFormat::Frames` writes `hh:mm:ss:ff` and needs `CsvWriteOptions::$frameRate` or a parsed frame rate.
- **No end column**: a cue without an end time ends at the next later start. The last such cue lasts `ReadOptions::$lastCueDuration`, 5 s by default.
- **Layout**: a subtitle from `CsvParser` keeps its columns, header names, delimiter and time format. So an unchanged table comes out byte for byte. A subtitle from another format gets the columns `start`, `end` and `text`. `identifier` comes first when a cue has one. `speaker` comes before `text` when a cue has a `<v>` tag.
- **Bilingual table**: `secondText` adds a column after `text`. Each row gets the cue of the second subtitle that overlaps the row most. When one second cue is the best match of several rows, only the first of them gets it.
- **Output**: a UTF-8 BOM by default, because Excel needs it to read UTF-8. `WriteOptions(bom: false)` leaves it out. `lineEnding` ends the rows. A line break inside a cell stays LF, as Excel writes it.
- **Formula injection**: `escapeFormulas: true` puts `'` before a cell that starts with `=`, `+`, `-` or `@`. Then a spreadsheet does not run the cell as a formula. It is off by default, because dialogue lines start with `-` and the option changes them.
- **Detection**: a CSV file has no signature, so pass `Format::Csv` or `Format::Tsv`. The command line tool reads `.csv` and `.tsv` files by their extension.

## EBU STL
EBU STL is the binary exchange format of European broadcasters, from [EBU Tech 3264](https://tech.ebu.ch/docs/tech/tech3264.pdf). Pass the bytes of the file unchanged.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\EbuStlWriteOptions;
use SubtitleToolbox\Parsers\Options\EbuStlReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents('news.stl'));  // detects EBU STL
$subtitle = Subtitle::fromString(file_get_contents('news.stl'), Format::EbuStl,
    new ReadOptions(format: new EbuStlReadOptions(subtractStartOfProgramme: true))); // cue times minus the start of programme
$subtitle->findFormatData('stl')['gsi']['TCP'];                                   // '10000000'
$subtitle->toString(Format::EbuStl, new WriteOptions(format: new EbuStlWriteOptions(frameRate: 30)));  // 25 or 30
```

- **Times**: the disk format code `STL25.01` or `STL30.01` sets the frame rate. By default, the parser keeps the time codes of the file.
- **Start of programme**: `EbuStlReadOptions(subtractStartOfProgramme: true)` subtracts the TCP time code, for example `10:00:00:00`. A time before it becomes 0. The formatter adds TCP again.
- **Characters**: the parser reads the character code tables 00 (ISO 6937) and 01 to 04 (ISO 8859-5, -6, -7 and -8). It needs no `mbstring` or `iconv`. The formatter writes `?` for a character outside the table.
- **Styles**: italics, underline and the 8 teletext colors become `<i>`, `<u>` and `<font color>`, and back. White gives no tag. The formatter drops other colors.
- **Blocks**: the parser joins the TTI blocks of one subtitle and skips user data blocks. A subtitle with the comment flag becomes a comment. The formatter splits long text into extension blocks.
- **Alignment**: the justification code gives the column. The vertical position gives the row: the top, middle or bottom third of the rows.
- **Metadata**: the title is the OPT field. The language is the LC field, for example `09` is `en`.
- **Format data**: the subtitle keeps the GSI fields by name in `gsi`, for example `DSC` and `TCP`. Each cue keeps its subtitle group, cumulative status, vertical position, justification code and original blocks.
- **Round trip**: an unchanged file comes out byte for byte. A cue with unchanged text keeps its text field bytes, also after retiming.
- **New files**: a subtitle from another format gets code page 850, 25 fps, level-1 teletext, character table 00, 40 characters, 23 rows and subtitle numbers from 1. The creation date is today.

## iTunes Timed Text
Apple TV and the iTunes Store take subtitles as iTunes Timed Text (iTT). iTT is a TTML profile with SMPTE frame times.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString(file_get_contents('movie.itt'), Format::Itt);
$subtitle->findFormatData('itt');  // ['timeBase' => 'smpte', 'frameRate' => '24', 'frameRateMultiplier' => '999 1000', 'dropMode' => 'nonDrop']
$subtitle->toString(Format::Itt);
Subtitle::fromStringAutoDetectFormat($srt)->toString(Format::Itt, new WriteOptions(format: new IttWriteOptions(frameRate: 23.976)));
```

- **Parser**: `IttParser` is `TtmlParser` plus the `itt` format data. Format detection returns `Format::Ttml` for an iTT file. Both read the same cues.
- **Frame rate**: the formatter takes it from `IttWriteOptions::$frameRate`, else from the `itt` format data. `IttWriteOptions` accepts 23.976, 24, 25, 29.97 and 30. Without a frame rate, the formatter throws `InvalidArgumentException`.
- **Times**: a time such as `00:00:01:12` is an SMPTE time code at the effective frame rate. So at 29.97 fps `01:00:00:00` is 3603.6 s. The formatter rounds each time to the nearest frame and gives each cue at least one frame. It always writes `ttp:dropMode="nonDrop"`.
- **Apple limits**: one `div`, `sansSerif` as the only font family, and a fixed `<head>` with the `top` and `bottom` regions. Alignment 7, 8 and 9 go to `top`, all others to `bottom`. The formatter does not keep the `<head>` or the attributes of the input file.
- **Markup**: the formatter writes `<b>`, `<i>`, `<u>` and `<font color>` as `tts:` attributes on `<span>`. It writes a color only as `#rrggbb` or a TTML color name, and drops an alpha channel. It strips `<s>`, `<v>` and word timestamps.
- **Forced cues**: see [subtitle.md](subtitle.md#forced-cues).

## LRC
LRC holds song lyrics with a time per line.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('song.lrc'), Format::Lyrics, new ReadOptions(lastCueDuration: 4));
$subtitle->findMetadata(Subtitle::METADATA_TITLE);                         // from [ti:]
$subtitle->findFormatData('lrc');                                          // ['idTags' => ['by' => 'Jane Doe']]
$subtitle->toString(Format::Lyrics);                                       // ID tags first, then the lyrics
```

- **ID tags**: `[ti:]`, `[ar:]`, `[al:]` and `[au:]` become the metadata keys `title`, `artist`, `album` and `author`. The parser keeps all other ID tags in the `lrc` format data. `[#:]` lines become comments. The formatter writes ID tags at the top and each comment before its cue.
- **Offset**: the parser subtracts `[offset:]` milliseconds from every time, so `[offset:+500]` turns `[00:12.00]` into 11.5 s. The formatter writes the shifted times and no `[offset:]` tag.
- **Lines**: a line can hold several timestamps. Enhanced LRC word times such as `<00:12.50>` become word timestamps, and back.
- **End times**: a cue ends at the next timestamp in time order. A timestamp without text, such as `[00:17.20]`, only ends the cue before it. The formatter writes such a line back. The last cue lasts `ReadOptions::$lastCueDuration`, 5 s by default.
- **Output**: times in centiseconds. The formatter strips all tags. Text with `<`, `>` and `&` round-trips.

## MicroDVD
MicroDVD counts time in video frames, so the parser and the formatter need the frame rate of the video.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString(file_get_contents('movie.sub'), Format::MicroDvd, new ReadOptions(format: new MicroDvdReadOptions(frameRate: 23.976)));
$subtitle = Subtitle::fromString(file_get_contents('movie.sub'), Format::MicroDvd);   // reads {1}{1}23.976

$subtitle->toString(Format::MicroDvd, new WriteOptions(format: new MicroDvdWriteOptions(
    frameRate: 23.976,               // required
    writeFrameRateLine: true,        // writes {1}{1}23.976 first
)));
```

- **Frame rate**: `MicroDvdReadOptions::$frameRate` wins over a `{1}{1}<fps>` first line. The parser never reads that line as a cue. Without either, the parser throws `ParsingException`.
- `$subtitle->findFormatData('microdvd')['frameRate']` returns the frame rate that the parser used.
- **Control codes**: `{y:b}`, `{y:i}`, `{y:u}`, `{y:s}` and `{c:$BBGGRR}` become core markup. The parser reads the codes at the start of each `|`-separated line. A code later in the line stays text. A lower-case code styles one line. An upper-case code styles the whole cue. The `microdvd` format data keeps other control codes.
- **Output**: the formatter writes control codes only for tags that wrap a whole line. It strips other tags. An unchanged cue keeps its original control codes.

## MPL2 and TMPlayer
Both formats are common in Polish subtitle downloads and use the `.txt` extension.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents('film.txt'), new ReadOptions(encoding: 'Windows-1250'));   // detects MPL2 or TMPlayer
$subtitle = Subtitle::fromString(file_get_contents('film.txt'), Format::TmPlayer, new ReadOptions(lastCueDuration: 3));
$subtitle->toString(Format::Mpl2);                                         // [12][45]Where are you?|/Home.
$subtitle->toString(Format::TmPlayer);                                     // 00:00:01:Where are you?|Home.
```

| Input | Parser result | Formatter output |
|:--- |:--- |:--- |
| MPL2 `[12][45]Where are you?\|/Home.` | 1.2 s to 4.5 s, `Where are you?` and `<i>Home.</i>` | the same line |
| TMPlayer `00:00:01:Rain`, `00:00:04:` and `00:00:09:Sun` | 1 s to 4 s, and 9 s to 14 s | the same three lines |
| TMPlayer+ `0:00:01=Hello` | 1 s to the next line | `00:00:01:Hello` |
| TMPlayer+ `00:00:01,1=Hello` and `00:00:01,2=world` | one cue with two lines | `00:00:01:Hello\|world` |

- **MPL2 times**: tenths of a second.
- **Italics**: the MPL2 formatter writes `/` for a line whose whole text is inside `<i>`. Both formatters strip all other tags.
- **TMPlayer end times**: a cue ends at the next line with a time. A line without text only ends the cue before it. The last cue lasts `ReadOptions::$lastCueDuration`, 5 s by default.
- **TMPlayer gaps**: TMPlayer counts whole seconds, so the formatter rounds each time to the second. A cue that ends before the next cue starts gets a line without text at its end. For a cue shorter than 1 s, that line comes 1 s after the start.
- **`.txt` files**: see [cli.md](cli.md#formats-and-file-extensions).

## MPSub
```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MpSubWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString(file_get_contents('movie.mpsub'), Format::MpSub);
$subtitle->toString(Format::MpSub);                                               // FORMAT=TIME
$subtitle->toString(Format::MpSub, new WriteOptions(format: new MpSubWriteOptions(frameRate: 25)));   // FORMAT=25 and frame counts
```

- **Header lines**: the parser reads `TITLE` and `AUTHOR` into the metadata. It keeps all other header lines, such as `TYPE` and `NOTE`, in the `mpsub` format data. `FORMAT` only sets the time unit. The formatter writes these lines back, and writes empty `TITLE` and `AUTHOR` lines when the values are not set.
- **Frame rate**: MPlayer and FFmpeg read only the integer part of `FORMAT=29.97`. So the parser uses 29 fps, and the formatter accepts only whole frame rates.
- **Overlapping cues**: a cue that starts before the previous cue ends gets a negative wait, for example `-1.5 2`. The parser reads it back. FFmpeg accepts a negative wait.
- **Output**: the formatter strips all tags. Text with `<`, `>` and `&` round-trips.

## SAMI
```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\SamiReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('movie.smi'), Format::Sami);   // the first class of the STYLE block
$subtitle = Subtitle::fromString(file_get_contents('movie.smi'), Format::Sami, new ReadOptions(format: new SamiReadOptions(languageClass: 'FRCC')));   // the FRCC class
$subtitle->findMetadata(Subtitle::METADATA_LANGUAGE);                             // 'fr-FR', from the lang property of .FRCC
$subtitle->findFormatData('sami');                                                // keys style, class and samiParam
```

- **Language class**: a SAMI file holds one CSS class per language, for example `.FRCC { Name: French; lang: fr-FR; }`. The parser reads the class in `SamiReadOptions::$languageClass`, else the first class of the STYLE block. Without a STYLE block, it reads the first class that a `<P>` uses. A `<P>` without a class belongs to every class.
- **End times**: a cue ends at the next `SYNC` that has a `<P>` of the same class, or no `<P>` at all. A `SYNC` with only `&nbsp;` ends a cue and starts none. The last cue lasts `ReadOptions::$lastCueDuration`, 5 s by default.
- **Text**: a line break in the file is a space, as in HTML. Only `<br>` starts a new cue line. `<b>`, `<i>`, `<u>`, `<s>`, `<strike>` and `<font color>` become core markup. `<font color>` accepts `#rrggbb`, `rrggbb` and the 16 color names of HTML 4. The parser drops other tags from the cue text.
- **Formatter**: it keeps `<b>`, `<i>`, `<u>`, `<s>` and `<font>` and strips all other tags. It writes the stored `<TITLE>`, STYLE block and `<SAMIParam>`, without the rules of the other language classes. Without a stored block, it names the class after the language metadata, for example `KOKRCC` for `ko-KR`, or `SUBTTL` without a language.
- **Timing**: the formatter writes a `&nbsp;` SYNC after each cue that has a gap before the next cue. A cue that overlaps the next cue ends where the next cue starts. An unchanged cue keeps the HTML of its `<P>`.
- **Encoding**: the parser reads UTF-8 only. It throws `ParsingException` for other encodings. For a file in EUC-KR or CP949, pass `ReadOptions::$encoding`, see [encodings.md](encodings.md).

## SBV
SBV is the YouTube caption format `0:00:01.500,0:00:04.000`.

- **Parser**: accepts any number of hour digits.
- **Formatter**: writes one hour digit below 10 hours, and no UTF-8 BOM. It strips all tags and decodes HTML entities. Text with `<`, `>` and `&` round-trips.

## Scenarist Closed Captions
US broadcast and many streaming services take closed captions as SCC. Each line of an SCC file is a time code and CEA-608 byte pairs, one pair per frame at 29.97 fps.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\SccWriteOptions;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents('show.scc'));  // detects SCC
$subtitle->findFormatData('scc');                                                 // ['dropFrame' => true]
$subtitle->getCues()[0]->findFormatData('scc');                                   // ['mode' => 'pop-on', 'rows' => [14, 15], 'columns' => [4, 8]]
Subtitle::fromString($content, Format::Scc, new ReadOptions(format: new SccReadOptions(channel: 2)));   // CC2 or CC4

$subtitle->wrapLines(32, 4)->toString(Format::Scc);
$subtitle->toString(Format::Scc, new WriteOptions(format: new SccWriteOptions(dropFrame: false)));
```

- **Reads**: pop-on, roll-up and paint-on captions, as the screen model of [47 CFR 15.119](https://www.govinfo.gov/content/pkg/CFR-2010-title47-vol1/xml/CFR-2010-title47-vol1-sec15-119.xml) defines them. Each change of the displayed captions starts a new cue. So a roll-up file gives one cue per screen, and a row shows in each cue until it rolls off.
- **Writes**: pop-on captions on data channel 1, with drop-frame time codes by default.
- **Times**: a semicolon before the frames marks drop-frame time code, a colon marks non-drop time code. A caption that no command erases lasts `ReadOptions::$lastCueDuration`, 5 s by default.
- **Damaged data**: the parser ignores the second copy of a doubled control code and drops a byte with a parity error. It skips data channel 2, XDS packets and text mode.
- **Position**: rows 1 to 4 give alignment 8, and all other rows give `null`. The `scc` format data keeps the row and column of each line. The formatter writes them back when they still fit the cue. Else it places the lines by the alignment, at the bottom and centred by default.
- **Timing of the formatter**: it loads each caption before the cue start, so the caption shows on the first frame of the cue. When the frames after the previous caption are too few for the load, the caption shows late. Of two overlapping cues, the later one replaces the earlier one.
- **Markup**: styles become `<i>`, `<u>` and `<font color>` with `#ffffff`, `#00ff00`, `#0000ff`, `#00ffff`, `#ff0000`, `#ffff00` and `#ff00ff`, and back. The formatter writes other colors as white and strips all other tags. A style change inside a word adds a space.
- **Limits**: the formatter throws `InvalidArgumentException` for more than 4 lines, more than 32 characters per line, or a character outside the CEA-608 character sets. Call `wrapLines(32, 4)` first.

## SubRip
| Input | Parser result | Formatter output |
|:--- |:--- |:--- |
| `0:00:01.5` | 1.5 s. Accepts a dot, one to three hour digits and one to three millisecond digits. | `00:00:01,500` |
| `X1:100 X2:600 Y1:40 Y2:80` after the end time | `findFormatData('srt')['coordinates']` | the same coordinates |
| `{\an8}` anywhere in the cue | alignment 8. The first tag wins. SSA `{\a6}` also becomes 8. | `{\an8}` at the start of the first line, nothing for 2 or `null` |
| `{\b1}`, `{\i1}`, `{\u1}`, `{\s1}` and their `0` forms | `<b>`, `<i>`, `<u>`, `<s>` and their closing tags. An open tag closes at the end of the cue. | the HTML-like tags |

- Other override tags such as `{\pos(10,20)}` stay in the cue text.
- The formatter keeps `<b>`, `<i>`, `<u>`, `<s>` and `<font>`, and strips all other tags.

## SubViewer
SubViewer 1 and 2 are `.sub` formats from older DivX releases and DVD rippers. One parser reads both versions.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\SubViewerVersion;
use SubtitleToolbox\Formatters\Options\SubViewerWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString(file_get_contents('movie.sub'), Format::SubViewer);
$subtitle->findFormatData('subviewer');  // ['version' => 2, 'header' => ['DELAY' => '0', 'CD TRACK' => '0'], 'style' => '[COLF]&HFFFFFF,[STYLE]bd,[SIZE]18,[FONT]Arial']
$subtitle->toString(Format::SubViewer);                                             // SubViewer 2
$subtitle->toString(Format::SubViewer, new WriteOptions(format: new SubViewerWriteOptions(version: SubViewerVersion::V1)));  // SubViewer 1
```

- **Version**: a `******** START SCRIPT ********` line makes a file SubViewer 1. All other files are SubViewer 2.
- **Header**: `[TITLE]` and `[AUTHOR]` become the metadata keys `title` and `author`. The parser keeps the other header tags and the `[COLF]` style line in the `subviewer` format data. The formatter writes them back after `[TITLE]` and `[AUTHOR]`. For a subtitle from another format, it writes the tags that Subtitle Edit writes.
- **Delay**: the parser adds the SubViewer 1 `[DELAY]` seconds to every time, as FFmpeg does, and stores `[DELAY]` as 0. It keeps the SubViewer 2 `[DELAY]` value and does not apply it.
- **SubViewer 2 text**: `[br]` and each text line become a cue line. The formatter writes all lines of a cue on one line, joined by `[br]`.
- **SubViewer 1 cues**: a `[00:00:01]` line with text below starts a cue. A time line with an empty line below ends the cue before it. A cue without such an end line ends where the next cue starts. The last cue lasts `ReadOptions::$lastCueDuration`, 5 s by default. `|` is a line break. As in FFmpeg, the parser reads only the first text line after a time line.
- **Output**: times in centiseconds for version 2 and in seconds for version 1. The formatter strips all tags and decodes HTML entities. It skips cues without text, because an empty line ends a cue.

## TTML
TTML covers TTML 1, TTML 2, IMSC and DFXP files.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('movie.ttml'), Format::Ttml);
$subtitle->findFormatData('ttml')['head'];             // <head> without ttm:title, as XML
$subtitle->getCues()[0]->findFormatData('ttml');       // ['attributes' => ['region' => 'bottom'], 'div' => [...]]
$subtitle->toString(Format::Ttml);
```

- **Time expressions**: `00:00:01.500`, `00:00:01:12` with frames, and `1.5s`, `1500ms`, `36f`, `15000000t`. Frames use `ttp:frameRate` and `ttp:frameRateMultiplier`, 30 fps by default. Ticks use `ttp:tickRate`. The parser adds the `begin` of the parent `body` and `div` elements. The formatter writes `00:00:01.500`.
- **End times**: a paragraph without `end` or `dur` ends with its parent. Without any end, the parser throws `ParsingException`.
- **Styles**: the parser resolves the `style` references and the inline `tts:` attributes of `<p>` and `<span>`. Bold, italic, oblique, underline, line-through and the text color become core markup. White text gives no `<font>` tag. The formatter writes each tag as a `<span>` with an inline style.
- **Speakers**: `ttm:agent` becomes `<v Name>`, with the name from the `ttm:name` of the agent. The formatter adds a `ttm:agent` element to the head for a new name.
- **Alignment**: the parser maps the region to an alignment only for `tts:textAlign` `left`, `center` or `right`. A text anchor in the top third of the screen gives the top row, in the bottom third the bottom row.
- **Regions from alignment**: a cue without a stored `region` gets a region such as `topCenter` that matches its alignment. A subtitle from another format gets `bottomCenter` for cues without alignment. The stored `region` wins over the alignment.
- **Metadata**: `xml:lang` of `<tt>` is the `language` and the first `ttm:title` is the `title`. The formatter writes the title as the first child of `<head>`.
- **Kept as is**: the `<head>`, the attributes of `<tt>`, `<body>`, `<div>` and `<p>`, and the namespace, so a DFXP file stays DFXP. The formatter drops `ttp:timeBase`, `ttp:clockMode`, `ttp:dropMode` and `ttp:markerMode`, because it writes media times.
- **Limits**: the parser reads `seq` time containers as `par` and ignores the timing of `<span>` elements. The formatter strips word timestamps. A cue identifier that is not a valid `xml:id` is not written.
- **Security**: the parser loads no external entity or DTD and makes no network access.
- **Forced cues**: see [subtitle.md](subtitle.md#forced-cues).

## WebVTT
| Input | Goes to |
|:--- |:--- |
| `NOTE` block | `$subtitle->getComments()` |
| Cue identifier | `$cue->getIdentifier()` |
| Text after `WEBVTT`, lines up to the first empty line | `$subtitle->findFormatData('vtt')`, keys `header` and `headerLines` |
| `STYLE` blocks, CSS not parsed | `$subtitle->findFormatData('vtt')['styles']` |
| `REGION` blocks | `$subtitle->findFormatData('vtt')['regions']`, one `name => value` array per region |
| Cue settings `vertical`, `line`, `position`, `size`, `align`, `region` | `$cue->findFormatData('vtt')`, exact values |
| `&nbsp;`, `&lrm;`, `&rlm;` | the characters U+00A0, U+200E, U+200F |

- **Alignment from cue settings**: `line:0` is the top row, `line:50%,center` the middle row, and no `line`, `line:-1` or `line:100%,end` the bottom row. `align:left`, `center` and `right` set the column. Other values, `align:start`, `align:end` and `vertical` give no alignment.
- **Cue settings from alignment**: a cue without `vtt` format data gets settings from its alignment. Alignment 8 becomes `line:0`, 7 becomes `line:0 align:left`. The `vtt` format data wins over the alignment.
- **Output**: the formatter writes the header, comments, styles, regions and cue settings back. It numbers cues without an identifier and always writes hours. It writes `REGION` blocks before `STYLE` blocks, and both before the comments that come before the first cue.
- **Markup**: the formatter keeps `<b>`, `<i>`, `<u>`, `<v>`, `<lang>`, `<c>`, `<ruby>`, `<rt>` and word timestamps. It keeps classes such as `<c.yellow>` and strips all other tags.
