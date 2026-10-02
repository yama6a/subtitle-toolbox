# Subtitle Toolbox
A PHP library that parses subtitles, lets you edit their cues, and writes them out in another format.
Pull requests are welcome.

## Install
Needs PHP 8.2 or later.

```sh
composer require ymakhloufi/subtitle-toolbox
```

## Usage
```php
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::parse(file_get_contents('movie.srt'), SubRipParser::class);
file_put_contents('movie.vtt', $subtitle->format(WebVttFormatter::class));
```

## Retiming
```php
$subtitle->shift(-2.5);                              // all cues 2.5 s earlier
$subtitle->shift(3, 600);                            // only cues that start at 600 s or later
$subtitle->scale(1.001);                             // multiply all times by 1.001
$subtitle->convertFrameRate(25, 23.976);             // subtitle for a 25 fps video, video is 23.976 fps
$subtitle->syncByTwoPoints(10, 12, 6260, 6005);      // 10 s becomes 12 s, 6260 s becomes 6005 s
```

- A start or end time that becomes negative becomes 0. The cue stays in the subtitle.
- `FrameRate` converts between frames and seconds: `(new FrameRate(23.976))->framesToSeconds(1000)` returns about 41.708.

## Metadata, comments and cue identifiers
Parsers fill these fields where their format has them, and formatters write them back. The format sections below list what each format keeps.

```php
$subtitle->setMetadata(Subtitle::METADATA_TITLE, 'Yesterday');
$subtitle->getMetadata('title');            // 'Yesterday'
$subtitle->setMetadata('title', null);      // removes the key
$subtitle->getAllMetadata();                // []

$subtitle->addComment('Translated by Jane Doe', 0);
$subtitle->getComments();                   // [['text' => 'Translated by Jane Doe', 'beforeCueIndex' => 0]]

$cue->setIdentifier('intro');
```

- **Metadata keys**: `Subtitle` has constants for the shared keys `title`, `author`, `artist`, `album` and `language`.
- **Comments**: a comment comes before the cue at `beforeCueIndex`. An index equal to the cue count puts it after the last cue.
- **Re-index**: `reIndexCues()` moves each comment together with its cue. A comment before a removed cue moves to the next cue.

## Core markup
Cue lines hold HTML-like inline tags. Parsers convert their styling to this tag set. Formatters strip the tags that their format cannot show.

| Tag | Meaning |
|:--- |:--- |
| `<b>` | bold |
| `<i>` | italic |
| `<u>` | underline |
| `<s>` | strikethrough |
| `<font color="#ff0000">` | text colour |
| `<v Fred>` | speaker |
| `<00:01:02.500>` | word timestamp |

Text that is not markup keeps `&lt;`, `&gt;` and `&amp;` escaped. The `Markup` class has the helpers that the formatters use:

```php
Markup::stripAllTags('<b>Hi</b> &amp; bye');            // 'Hi &amp; bye'
Markup::keepTags('<b>Hi</b> <c.red>you</c>', ['b']);    // '<b>Hi</b> you'
Markup::decodeEntities('Hi &amp; bye');                 // 'Hi & bye'
```

## Alignment and format data
SubRip and WebVTT read and write the alignment. Every parser keeps the data of its format that has no shared field in the format data.

```php
$cue->setAlignment(8);                                  // top center
$cue->setFormatData('ass', ['style' => 'Sign']);
$subtitle->getFormatData('ass');                        // [] when not set
```

- **Alignment**: a number from 1 to 9 in numeric keypad layout. 1 is bottom left, 2 is bottom center, 8 is top center. `null` means the format default, bottom center.
- **Format data**: styling outside the core markup and the alignment. Only the formatter of the same format reads it. The key is the lowercase file extension of the format, for example `ass` or `vtt`.

## Supported formats
| Format | Reads | Outputs | Additional Info
|:--- |:--- |:--- |:--- |
| ASS (.ass)      | Script Info, styles, other sections, `Dialogue:` and `Comment:` events, columns by the `Format:` line | Writes them back, the original event text for unchanged cues, a minimal header for cues from other formats | Converts `\b`, `\i`, `\u`, `\s`, `\c`, alignment, karaoke and the Name field to core markup. Writes times in centiseconds
| EBU STL (.stl)  | Binary EBU Tech 3264 files at 25 or 30 fps, character code tables 00 to 04, extension blocks | The stored GSI and TTI blocks, 25 fps by default, `OPTION_FRAME_RATE` for 30 fps | Converts italics, underline and the teletext colours to core markup. See [EBU STL](#ebu-stl)
| iTunes Timed Text (.itt) | The TTML parser with the SMPTE timing parameters in the `itt` format data | SMPTE times `hh:mm:ss:ff`, one `div`, a `top` and a `bottom` region. Needs a frame rate | Writes bold, italic, underline and text colour as `tts:` attributes on `<span>`. Strips all other tags
| LyRiCs (.lrc)   | ID tags, `[offset:]`, several timestamps per line, enhanced LRC word timing | ID tags, `[#:]` comments, word timing as `<mm:ss.xx>` | Formatter strips all other xml tags and writes times in centiseconds. Text with `<`, `>` and `&` round-trips
| MicroDVD (.sub) | Frame rate from the parser constructor or a `{1}{1}<fps>` first line | Needs `OPTION_FRAME_RATE` | Converts `{y:b}`, `{y:i}`, `{y:u}`, `{y:s}` and `{c:$BBGGRR}` to core markup. Keeps other control codes in the `sub` format data
| MpSub (.mpsub)  | FORMAT=TIME and FORMAT=<fps>, header lines | FORMAT=TIME by default, FORMAT=<fps> as an option, header lines | Formatter strips all xml tags. Text with `<`, `>` and `&` round-trips
| PGS (.sup)      | Blu-ray bitmaps as image cues, with palettes, cropping, windows and forced flags | Not supported | See [PGS](#pgs)
| SAMI (.smi)     | One language class, `<TITLE>`, the `<STYLE>` block and `<SAMIParam>` | Writes them back, and a `&nbsp;` SYNC after each cue that has a gap before the next cue | Converts `<b>`, `<i>`, `<u>`, `<s>`, `<strike>` and `<font color>` to core markup. Formatter strips all xml tags except: \<b>\<i>\<u>\<s>\<font>
| SBV (.sbv)      | Accepts any number of hour digits | Writes one hour digit below 10 hours, no UTF-8 BOM | Formatter strips all xml tags and decodes HTML entities. Text with `<`, `>` and `&` round-trips
| SCC (.scc)      | Pop-on, roll-up and paint-on CEA-608 captions, drop-frame and non-drop time codes | Pop-on captions on data channel 1, drop-frame time codes by default | Converts PAC styles and mid-row codes to `<i>`, `<u>` and `<font color>` and back. Strips all other tags. Throws for more than 4 lines or 32 characters per line
| SSA (.ssa)      | SubStation Alpha v4.00 with `[V4 Styles]` and `Marked=` columns | Writes SSA back when the parsed file was SSA, legacy `\a` alignment tags | Same parser and formatter as ASS
| SubRip (.srt)   | Reads coordinates, alignment tags and lenient timestamps | Writes standard timestamps, coordinates and alignment tags | Formatter strips all xml tags except: \<b>\<i>\<u>\<s>\<font>
| SubViewer (.sub) | SubViewer 1 and 2, header tags, the `[COLF]` style line | SubViewer 2 by default, SubViewer 1 with `OPTION_VERSION` | Formatter strips all xml tags and decodes HTML entities. Writes times in centiseconds for version 2 and in seconds for version 1
| TTML (.ttml, .dfxp, .xml) | TTML 1, TTML 2, IMSC and the DFXP namespace. All time expressions, `body` and `div` offsets | Media clock times, `<head>` and attributes of the input file | Converts `tts:fontWeight`, `tts:fontStyle`, `tts:textDecoration`, `tts:color` and `ttm:agent` to core markup and back
| VobSub (.idx and .sub) | DVD bitmaps as image cues. The `size`, `palette`, `custom colors`, `id`, `delay` and `timestamp` lines of the `.idx` | Not supported | See [VobSub](#vobsub)
| WebVTT (.vtt)   | Header, comments, cue identifiers, styles, regions and cue settings | Writes them back, numbers cues without identifier, always writes hours | Formatter strips all xml tags except: \<b>\<u>\<i>\<v>\<lang>\<c>\<ruby>\<rt> and inline timestamps
| Whisper JSON (.json) | The JSON of the OpenAI transcription API, openai-whisper, faster-whisper, WhisperX and whisper.cpp | Not supported | See [Whisper JSON](#whisper-json)

### LRC
```php
$subtitle = (new LyricsParser(lastCueDuration: 4))->parse(file_get_contents('song.lrc'));
$subtitle->getMetadata(Subtitle::METADATA_TITLE);                          // from [ti:]
$subtitle->getFormatData('lrc');                                           // ['idTags' => ['by' => 'Jane Doe']]
$subtitle->format(LyricsFormatter::class);                                 // ID tags first, then the lyrics
```

- **ID tags**: `[ti:]`, `[ar:]`, `[al:]` and `[au:]` become the metadata keys `title`, `artist`, `album` and `author`. The parser keeps all other ID tags in the `lrc` format data. `[#:]` lines become comments. The formatter writes ID tags at the top and each comment before its cue.
- **Offset**: the parser subtracts `[offset:]` milliseconds from every time, so `[offset:+500]` turns `[00:12.00]` into 11.5 s. The formatter writes the shifted times and no `[offset:]` tag.
- **End times**: a cue ends at the next timestamp in time order. A timestamp without text, such as `[00:17.20]`, only ends the cue before it. The formatter writes such a line back. The last cue lasts 10 s unless you pass `lastCueDuration`.

### MicroDVD
MicroDVD counts time in video frames, so the parser and the formatter need the frame rate of the video.

```php
$subtitle = (new MicroDvdParser(23.976))->parse(file_get_contents('movie.sub'));
$subtitle = Subtitle::parse(file_get_contents('movie.sub'), MicroDvdParser::class);   // reads {1}{1}23.976

$subtitle->format(MicroDvdFormatter::class, [
    MicroDvdFormatter::OPTION_FRAME_RATE            => 23.976,
    MicroDvdFormatter::OPTION_WRITE_FRAME_RATE_LINE => true,                         // writes {1}{1}23.976 first
]);
```

- **Frame rate**: the constructor value wins over a `{1}{1}<fps>` first line. The parser never reads that line as a cue. Without either, the parser throws `ParsingException`.
- `$subtitle->getFormatData('sub')['frameRate']` returns the frame rate that the parser used.
- **Control codes**: the parser reads the codes at the start of each `|`-separated line. A code later in the line stays text.
- A lower-case code styles one line. An upper-case code styles the whole cue.
- The formatter writes control codes only for tags that wrap a whole line. It strips other tags.
- An unchanged cue keeps its original control codes. The formatter writes `{y:b}{y:i}` for a changed cue or a cue from another format.

### MPSub
```php
$subtitle = Subtitle::parse(file_get_contents('movie.sub'), MpSubParser::class);
$subtitle->format(MpSubFormatter::class, [MpSubFormatter::OPTION_FRAME_RATE => 25]);    // FORMAT=25 and frame counts
```

- **Header lines**: the parser reads `TITLE` and `AUTHOR` into the metadata. It keeps all other header lines, such as `TYPE` and `NOTE`, in the `mpsub` format data. `FORMAT` only sets the time unit. The formatter writes these lines back, and writes empty `TITLE` and `AUTHOR` lines when the values are not set.
- **Frame rate**: MPlayer and FFmpeg read only the integer part of `FORMAT=29.97`. So the parser uses 29 fps, and the formatter accepts only whole frame rates.
- **Overlapping cues**: a cue that starts before the previous cue ends gets a negative wait, for example `-1.5 2`. The parser reads it back. FFmpeg accepts a negative wait.

### SubRip
| Input | Parser result | Formatter output |
|:--- |:--- |:--- |
| `0:00:01.5` | 1.5 s. Accepts a dot, one to three hour digits and one to three millisecond digits. | `00:00:01,500` |
| `X1:100 X2:600 Y1:40 Y2:80` after the end time | `getFormatData('srt')['coordinates']` | the same coordinates |
| `{\an8}` anywhere in the cue | alignment 8. The first tag wins. Legacy SSA `{\a6}` also becomes 8. | `{\an8}` at the start of the first line, nothing for 2 or `null` |
| `{\b1}`, `{\i1}`, `{\u1}`, `{\s1}` and their `0` forms | `<b>`, `<i>`, `<u>`, `<s>` and their closing tags. An open tag closes at the end of the cue. | the HTML-like tags |

Other override tags such as `{\pos(10,20)}` stay in the cue text.

### WebVTT
| Input | Goes to |
|:--- |:--- |
| `NOTE` block | `$subtitle->getComments()` |
| Cue identifier | `$cue->getIdentifier()` |
| Text after `WEBVTT`, lines up to the first empty line | `$subtitle->getFormatData('vtt')`, keys `header` and `headerLines` |
| `STYLE` blocks, CSS not parsed | `$subtitle->getFormatData('vtt')['styles']` |
| `REGION` blocks | `$subtitle->getFormatData('vtt')['regions']`, one `name => value` array per region |
| Cue settings `vertical`, `line`, `position`, `size`, `align`, `region` | `$cue->getFormatData('vtt')`, exact values |
| `&nbsp;`, `&lrm;`, `&rlm;` | the characters U+00A0, U+200E, U+200F |

- **Alignment from cue settings**: `line:0` is the top row, `line:50%,center` the middle row, and no `line`, `line:-1` or `line:100%,end` the bottom row. `align:left`, `center` and `right` set the column. Other values, `align:start`, `align:end` and `vertical` give no alignment.
- **Cue settings from alignment**: a cue without `vtt` format data gets settings from its alignment. Alignment 8 becomes `line:0`, 7 becomes `line:0 align:left`. The `vtt` format data wins over the alignment.
- **Limits**: the formatter strips classes such as `<c.yellow>`. It writes `REGION` blocks before `STYLE` blocks, and both before the comments that come before the first cue.

### SAMI
```php
$subtitle = Subtitle::parse(file_get_contents('movie.smi'), SamiParser::class);   // the first class of the STYLE block
$subtitle = (new SamiParser('FRCC'))->parse(file_get_contents('movie.smi'));      // the FRCC class
$subtitle->getMetadata(Subtitle::METADATA_LANGUAGE);                              // 'fr-FR', from the lang property of .FRCC
$subtitle->getFormatData('smi');                                                  // keys style, class and samiParam
```

- **Language class**: a SAMI file holds one CSS class per language, for example `.FRCC { Name: French; lang: fr-FR; }`. The parser reads one class. Without a STYLE block, it reads the first class that a `<P>` uses. A `<P>` without a class belongs to every class.
- **End times**: a cue ends at the next `SYNC` that has a `<P>` of the same class, or no `<P>` at all. A `SYNC` with only `&nbsp;` ends a cue and starts none. The last cue lasts 10 s unless you pass `lastCueDuration`.
- **Text**: a line break in the file is a space, as in HTML. Only `<br>` starts a new cue line. `<font color>` accepts `#rrggbb`, `rrggbb` and the 16 colour names of HTML 4. The parser drops other tags from the cue text. The cue format data keeps the HTML of each `<P>`, and the formatter writes it back for an unchanged cue.
- **Formatter**: it writes the stored STYLE block without the rules of the other language classes. Without a stored block, it names the class after the language metadata, for example `KOKRCC` for `ko-KR`, or `SUBTTL` without a language. A cue that overlaps the next cue ends where the next cue starts.
- **Encoding**: the parser reads UTF-8 only. It throws `ParsingException` for other encodings. For a file in EUC-KR or CP949, pass the encoding to `Subtitle::parse()`, as the section on encodings shows.

### ASS and SSA
`AssParser` reads ASS v4.00+ and SSA v4.00. `AssFormatter` writes the version that the parser read, or ASS for cues from other formats.

```php
$subtitle = Subtitle::parse(file_get_contents('episode.ass'), AssParser::class);
$subtitle->getFormatData('ass')['scriptInfo']['PlayResX'];      // '1920'
$subtitle->getCues()[0]->getFormatData('ass')['fields'];        // ['Layer' => '0', 'Style' => 'Default', ...]
$subtitle->format(AssFormatter::class);
```

| Input | Parser result | Formatter output |
|:--- |:--- |:--- |
| `{\b1}`, `{\i1}`, `{\u1}`, `{\s1}`, their `0` forms and `\r` | `<b>`, `<i>`, `<u>`, `<s>` and their closing tags | the same override tags |
| `{\c&H0000FF&}` or `{\1c&H0000FF&}`, colour as BGR | `<font color="#ff0000">` | `{\c&H0000FF&}`, `{\c}` at `</font>` |
| `{\an8}`, legacy SSA `{\a6}` | alignment 8. The first tag wins. | `{\an8}` in ASS, `{\a6}` in SSA. Nothing for `null`. |
| Name field `Fred` | `<v Fred>` at the start of the first line | the Name field |
| `{\k50}`, `{\kf50}`, `{\K50}`, `{\ko50}` in centiseconds | a word timestamp at the start time of each syllable | `{\k}`. The last syllable lasts until the cue end. |
| `\N`, `\h` | a new line, U+00A0 | `\N`, `\h` |
| `\n` | a space, or a new line with `WrapStyle: 2` | `\N` |
| `Comment:` event, `Title:` | `getComments()`, the `title` metadata | the stored `Comment:` event with the same text, `Title:` |

- **Format data**: ASS and SSA both use the key `ass`. The subtitle keeps `[Script Info]`, the styles, the `Format:` lines, the section order, `Comment:` events and other sections such as `[Fonts]` and `[Graphics]`. Each cue keeps its event fields and its original `Text` field.
- **Unchanged cues**: when the lines and the alignment of a cue are the same as after parsing, the formatter writes the original `Text` field. So tags such as `\pos`, `\fad` and `\t` survive an ASS round trip and a retiming.
- **Changed cues**: the formatter writes the text from the core markup. Other override tags are lost.
- **Limits**: other override tags, `{...}` notes and `\p1` drawings are not cue text. An event that holds only a drawing becomes a cue without lines. Style definitions do not change the core markup. Events come out in time order.
- **Output**: UTF-8 BOM and LF line endings. A cue from another format gets style `Default`. The minimal header has the same values as the header that FFmpeg writes.

### TTML
```php
$subtitle = Subtitle::parse(file_get_contents('movie.ttml'), TtmlParser::class);
$subtitle->getFormatData('ttml')['head'];              // <head> without ttm:title, as XML
$subtitle->getCues()[0]->getFormatData('ttml');        // ['attributes' => ['region' => 'bottom'], 'div' => [...]]
$subtitle->format(TtmlFormatter::class);
```

- **Time expressions**: `00:00:01.500`, `00:00:01:12` with frames, and `1.5s`, `1500ms`, `36f`, `15000000t`. Frames use `ttp:frameRate` and `ttp:frameRateMultiplier`, 30 fps by default. Ticks use `ttp:tickRate`. The parser adds the `begin` of the parent `body` and `div` elements. A paragraph without `end` or `dur` ends with its parent. Without any end, the parser throws `ParsingException`. The formatter writes `00:00:01.500`.
- **Styles**: the parser resolves the `style` references and the inline `tts:` attributes of `<p>` and `<span>`. Bold, italic, oblique, underline, line-through and the text colour become core markup. White text gives no `<font>` tag, because white is the default colour of every player. The formatter writes each tag as a `<span>` with an inline style.
- **Speakers**: `ttm:agent` becomes `<v Name>`, with the name from the `ttm:name` of the agent. The formatter adds a `ttm:agent` element to the head for a new name.
- **Alignment**: the parser maps the region to an alignment only for `tts:textAlign` `left`, `center` or `right`. The text anchor sets the row: the top edge of the region for `displayAlign="before"`, the middle for `center`, the bottom edge for `after`. An anchor in the top third of the screen is the top row, in the bottom third the bottom row. The region `tts:origin` and `tts:extent` can use `%`, `px` with a pixel `tts:extent` on the root, or `c` cells.
- **Regions from alignment**: a cue without a stored `region` gets a region such as `topCenter` that matches its alignment. A subtitle from another format gets `bottomCenter` for cues without alignment. The stored `region` wins over the alignment.
- **Metadata**: `xml:lang` of `<tt>` is the `language` and the first `ttm:title` is the `title`. The formatter writes the title as the first child of `<head>`.
- **Kept as is**: the `<head>`, the attributes of `<tt>`, `<body>`, `<div>` and `<p>`, and the namespace, so a DFXP file stays DFXP. The formatter drops `ttp:timeBase`, `ttp:clockMode`, `ttp:dropMode` and `ttp:markerMode`, because it writes media times.
- **Limits**: the parser reads `seq` time containers as `par` and ignores the timing of `<span>` elements. The formatter strips word timestamps. A cue identifier that is not a valid `xml:id` is not written.
- **Security**: the parser loads no external entity or DTD and makes no network access.

## Validation
`validate()` checks the cues against reading and timing rules. It returns one `ValidationResult` per broken rule. `getErrors()` stays as it is.

```php
use SubtitleToolbox\Validation\ValidationRules;

$results = $subtitle->validate(ValidationRules::netflixEnglish(23.976));
$results = $subtitle->validate(new ValidationRules(maxCharactersPerLine: 37, noEmptyCues: true));

$results[0]->getCueIndex();     // 1
$results[0]->getRule();         // 'maxCharactersPerLine', see the ValidationResult::RULE_* constants
$results[0]->getValue();        // 45
$results[0]->getLimit();        // 37
```

| Rule | Limit | Value |
|:--- |:--- |:--- |
| `maxCharactersPerSecond` | characters per second | characters of all lines divided by the duration. `INF` for a cue with text and no duration |
| `maxCharactersPerLine` | characters | one result per line that is too long |
| `maxLinesPerCue` | lines | lines with visible text |
| `minDuration`, `maxDuration` | seconds | end minus start |
| `minGap` | seconds | start minus the latest end of the earlier cues. Overlaps are not gaps |
| `noOverlap` | `true` to check, result limit `null` | seconds of overlap with the earlier cues |
| `noEmptyCues` | `true` to check, result limit `null` | 0 |

- **Off by default**: a rule with the limit `null` or `false` is off.
- **Characters**: the count leaves out tags and leading and trailing spaces. It counts an entity such as `&amp;` as one character and a UTF-8 letter of several bytes as one character.
- **Milliseconds**: cue times have millisecond precision. So a cue of 0.833 s meets a minimum duration of 5/6 s.
- **Netflix English preset**: 20 characters per second for adult programs, 42 characters per line, 2 lines, 5/6 s to 7 s, a gap of 2 frames at the given frame rate, no overlaps. The values come from the [English (USA) Timed Text Style Guide](https://partnerhelp.netflixstudios.com/hc/en-us/articles/217350977-English-USA-Timed-Text-Style-Guide), the [General Requirements](https://partnerhelp.netflixstudios.com/hc/en-us/articles/215758617) and the [Subtitle Timing Guidelines](https://partnerhelp.netflixstudios.com/hc/en-us/articles/360051554394).

## Format detection
File extensions do not identify a format. For example, a `.sub` file can be MicroDVD or MPSub. So the library reads the start of the content.

```php
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
| 11 | `PgsParser` | the bytes `PG`, then a known segment type at byte 10 |
| 12 | `JsonParser` | an object with a numeric `"version"` key and a `"cues"` list |
| 13 | `EbuStlParser` | a 3-digit code page such as `850`, then `STL25.01` or `STL30.01` |
| 14 | `SccParser` | `Scenarist_SCC V1.0` |
| 15 | `WhisperJsonParser` | an object with a `"segments"` or `"transcription"` list |

- **Order**: a format with a more specific signature comes first. A WebVTT file without its `WEBVTT` line looks like SubRip, so it detects as SubRip.
- **`.sub` files**: MicroDVD, MPSub and SubViewer text files all use `.sub`. SBV has three digits after the dot, SubViewer 2 has two.
- **MicroDVD**: detection does not find the frame rate. `parse()` throws `ParsingException` for a MicroDVD file without a `{1}{1}<fps>` first line. Then pass the frame rate: `(new MicroDvdParser(23.976))->parse($content)`.

## Editing cues
```php
$part1->merge($part2, 3130);                    // appends part 2, 3130 s later
$clip = $subtitle->slice(600, 1200, true);      // a new Subtitle with the cues from 600 s to 1200 s, moved to start at 0
$subtitle->splitCue(4, 63.5, 1);                // cue 4 becomes two cues at 63.5 s, line 1 in the first
$subtitle->joinCues(4, 5);                      // one cue with the lines of cue 4 and 5
$subtitle->removeDuplicateCues();               // joins touching cues with the same text
```

- **Merge**: the metadata and the format data of `$this` win over those of the merged file. The comments of both files stay before their cues. At the same place, the comments of `$this` come first.
- **Slice**: a cue that crosses `$from` or `$to` gets cut there. The copy keeps the metadata, the format data and the comments before the kept cues. The original stays unchanged.
- **Split and join**: the first cue keeps its identifier. A comment before a joined cue moves before the result.

## Fixing timing and layout
```php
$gap = (new FrameRate(24))->framesToSeconds(2);   // about 0.083 s

$subtitle->fixOverlaps($gap);                     // end each cue at least $gap before the next cue starts
$subtitle->extendShortCues(0.833, $gap);          // show each cue for at least 0.833 s where the next cue allows it
$subtitle->wrapLines(42);                         // at most 42 characters per line, at most 2 lines
$subtitle->unwrapLines();                         // join the lines of each cue with a space
```

- **Start times**: the fixes move only end times. `fixOverlaps()` ends a cue at its own start when the gap does not fit. `extendShortCues()` never creates an overlap and never makes a cue shorter.
- **Line breaks**: `wrapLines()` changes only cues with a longer line or with more lines than allowed. It uses the fewest lines that fit and makes them about equal in length. When the text does not fit, the lines get longer than the limit.
- **Characters**: tags count 0 characters, and an entity such as `&amp;` counts 1. `wrapLines()` breaks only at spaces outside tags. It closes the open core markup tags at a break and opens them again on the next line.
- **Text without spaces**: Chinese or Japanese text has no break points, so `wrapLines()` keeps such a line long.

## Encodings and line endings
```php
$subtitle = Subtitle::parse(file_get_contents('movie.srt'), SubRipParser::class, 'Windows-1252');
$subtitle = Subtitle::parse(file_get_contents('movie.smi'), null, 'CP949');
StringHelpers::isValidUtf8(file_get_contents('movie.srt'));   // false for a Windows-1252 file with letters such as é

$subtitle->format(SubRipFormatter::class, [
    SubtitleFormatter::OPTION_LINE_ENDING => "\r\n",   // "\n" (default) or "\r\n"
    SubtitleFormatter::OPTION_BOM         => false,    // true adds a UTF-8 BOM, false removes it
]);
```

- **Input**: the library converts UTF-16 and UTF-32 with a BOM to UTF-8 without being asked. A BOM wins over the source encoding argument. Without a BOM and without the argument, the parsers read the bytes as UTF-8 and keep invalid bytes.
- **Parsers called directly**: only `Subtitle::parse()` converts. Before `(new SamiParser())->parse($content)`, call `StringHelpers::convertToUtf8($content, 'CP949')`.
- **Source encodings**: the conversion uses the PHP extension iconv. It accepts the names that the iconv of the system knows, for example `Windows-1251`, `ISO-8859-15`, `Shift_JIS` or `EUC-KR`. An unknown name or a byte that is invalid in the encoding throws `ParsingException`.
- **Output defaults**: every formatter writes LF. ASS, LRC, MPSub, SubRip and WebVTT write a UTF-8 BOM. MicroDVD, SAMI, SBV and TTML do not.

## Image cues and OCR
Some formats store each cue as a bitmap. An **image cue** is a cue with a PNG image in the format data key `image`. It has no text lines until an OCR engine reads it. OCR (optical character recognition) turns the bitmap into text.

```php
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Image\CueImage;

$image = CueImage::fromCue($cue);                    // $image->png, x, y, width, height, screenWidth, screenHeight, forced
file_put_contents('cue.png', $image->png);

$subtitle->recognizeText(new TesseractEngine(), 'eng');   // sets the lines of each image cue without text
$subtitle->format(SubRipFormatter::class);

$subtitle->format(SubRipFormatter::class, [
    SubtitleFormatter::OPTION_SKIP_IMAGE_CUES => true,    // drops image cues without text
]);
```

- **Text formatters**: `format()` throws `ImageCueWithoutTextException` for an image cue without text. A file without OCR then fails at once, and does not become a valid file with missing cues.
- **After OCR**: the cue keeps its image, so a formatter that implements `ImageFormatter` can still write it. `ImageFormatter` formatters also get image cues without text.
- **Engines**: `GlyphOcrEngine` reads text in pure PHP with an optional package, see [Built-in OCR](#built-in-ocr). Other engines are separate Composer packages that implement `OcrEngine`. `TesseractEngine` above is such a package.
- **Language**: `recognizeText()` passes the language code to the engine as it is. Use a code that the engine knows, for example `eng` for Tesseract.
- **Confidence**: `(new OcrRunner($engine))->run($subtitle, 'eng')` does the same as `recognizeText()` and returns the `OcrResult` of each cue by cue index.
- **PNG**: `PngEncoder::encode($width, $height, $pixels)` makes a PNG from a list of `0xRRGGBBAA` integers. It needs no ext-gd. It compresses with ext-zlib when it is loaded, and else writes larger, uncompressed PNG files.

An engine package implements one method:

```php
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\OcrEngine;
use SubtitleToolbox\Ocr\OcrResult;

final class TesseractEngine implements OcrEngine
{
    public function recognize(CueImage $image, ?string $language): OcrResult
    {
        $file = tempnam(sys_get_temp_dir(), 'cue');
        file_put_contents($file, $image->png);
        $text = shell_exec('tesseract ' . escapeshellarg($file) . ' - -l ' . escapeshellarg($language ?? 'eng'));
        unlink($file);

        return new OcrResult(explode("\n", trim((string)$text)));
    }
}
```

- **Lines**: the engine returns plain text or core markup, for example `<i>` for italic text. Empty lines are dropped.
- **Confidence**: pass a value from 0 to 1 as the second argument of `OcrResult`, or leave it null.

## Sync to a reference subtitle
A German SRT for the 25 fps release is late and drifts against a 23.976 fps video. An English SRT for that video is in sync. `ReferenceSync` finds the scale and the offset from the cue times alone, so the languages can differ.

```php
use SubtitleToolbox\Sync\ReferenceSync;
use SubtitleToolbox\Sync\ReferenceSyncOptions;

$result = ReferenceSync::sync($german, $english);   // $german stays unchanged
$result->getScale();                                // 1.04271 (25 / 23.976)
$result->getOffset();                               // -2.3, added after the scale
$result->getScore();                                // 0.89
$result->apply($german);                            // calls scale() and then shift()

ReferenceSync::sync($german, $english, new ReferenceSyncOptions(
    minOffset: -120,       // seconds, default -60
    maxOffset: 120,        // seconds, default 60
    searchScale: false,    // true (default) tries the frame-rate factors, false keeps the scale at 1
));
```

- **Matching**: a candidate maps each target time t to t * scale + offset. Its score is the time that cues of both files cover, divided by the time that cues of at least one file cover. Only the times count, not the text. The idea comes from [alass](https://github.com/kaegi/alass), which also aligns by time spans.
- **Search**: the scale factors are 1, 24/23.976, 25/24 and 25/23.976 and their inverses, as in the frame-rate ratios of [ffsubsync](https://github.com/smacke/ffsubsync). For each factor, the search tries offsets in steps of 0.1 s, then steps of 0.01 s around the best one. The best score over all factors wins.
- **Score**: from 0 to 1. A score below 0.5 means the files likely do not match. Missing and extra cues lower the score. The result stays correct while most cues match.
- **Speed**: 2,000 cues against 2,000 cues take about 0.5 s.
- **Limits**: one scale and one offset apply to the whole file. A file with a different shift after a cut, which alass calls a split, does not sync. Other frame-rate factors and offsets outside the range are not found.

## Transforming text
```php
$subtitle->replaceText('Colour', 'Color');               // '<i>Colour</i> me' becomes '<i>Color</i> me'
$subtitle->replaceText('/\.{4,}/', '...', true);         // a regex with delimiters, '$1' works in the replacement
$subtitle->replaceText('colour', 'color', false, false); // case-insensitive
$subtitle->stripFormatting();                            // '<b>Run</b>, now!' becomes 'Run, now!'
$subtitle->stripFormatting(['i']);                       // keeps <i>, removes all other tags
$subtitle->changeCase('sentence');                       // 'WHERE ARE YOU? HOME.' becomes 'Where are you? Home.'
$subtitle->changeCase('upper', 'tr');                    // Turkish rules: 'istanbul' becomes 'İSTANBUL'
$subtitle->mapText(fn (string $text, SubtitleCue $cue): string => str_replace("''", '"', $text));
$subtitle->mapLines(fn (string $line, SubtitleCue $cue): string => "<i>$line</i>");
```

- **Text runs**: `replaceText()`, `changeCase()` and `mapText()` see only the text between tags, with `&lt;`, `&gt;` and `&amp;` decoded. A search for `&` finds `&amp;`. A search for `amp` or `font` finds no markup. The result gets escaped again, so a replacement cannot add tags. Use `mapLines()` to change tags.
- **Run limits**: a match cannot cross a tag. `replaceText('Colour', ...)` does not find `<i>Col</i>our`.
- **Word timestamps**: `stripFormatting()` keeps them. Pass `false` as the second argument to remove them too.
- **Empty cues**: a transform removes a cue that had text before and has only tags or spaces after. Comments stay before the next cue.
- **Upper and lower case**: with `ext-mbstring`, the full Unicode case mapping applies. `ß` becomes `SS`, and `ẞ` becomes `ß`. Lower case turns Greek `Σ` at the end of a word into `ς`. Without `ext-mbstring`, or for text that is not valid UTF-8, only the letters A to Z change.
- **Turkish and Azerbaijani**: pass `'tr'` or `'az'` as the second argument of `changeCase()`. Then `i` and `İ` pair, and `ı` and `I` pair. Without it, `İ` becomes `i` with a combining dot, U+0307.
- **Sentence case**: a sentence starts at the start of a cue, and at the first letter or digit after `.`, `!` or `?` and a space or line break. `www.example.com` stays lower case. `ß` at the start of a sentence becomes `Ss`. Names and the English word `I` become lower case. Fix them after with `replaceText()`.

## Statistics
```php
$stats = SubtitleStatistics::of($subtitle);
$stats->getCueCount();             // 612
$stats->getWordCount();            // 4870
$stats->getCharacterCount();       // 25310
$stats->getTotalDisplayTime();     // 1742.5, the sum of the cue durations in seconds
$stats->getSpan();                 // 2688.0, the seconds from the first start to the last end
$stats->getCharactersPerSecond();  // ['min' => 3.1, 'average' => 14.5, 'max' => 31.2]
$stats->getWordsPerMinute();       // ['min' => 40.0, 'average' => 168.0, 'max' => 390.0]
$stats->getCharactersPerLine();    // ['min' => 2.0, 'average' => 31.0, 'max' => 47.0]
$stats->getGap();                  // ['min' => 0.0, 'average' => 2.9, 'max' => 41.0]
$stats->getMostUsedWords(10);      // ['you' => 211, 'the' => 160, ...]
json_encode($stats->toArray());    // all numbers and the 10 most used words
```

- **Characters**: the count uses the rule of `validate()`. It leaves out tags and leading and trailing spaces. An entity such as `&amp;` and a UTF-8 letter of several bytes count as one character.
- **Words**: the text without tags, split at whitespace. A dialogue dash counts as a word. `getMostUsedWords()` removes punctuation at the start and end of each word and compares in lower case. PHP stores a word that is a plain integer such as `2024` as an int key, so cast a key to string before string use.
- **Cues without text**: an image cue counts in `getCueCount()`, the display time, the span and the gaps. The text numbers leave it out.
- **Reading speed**: a cue with a duration of 0 has no characters per second and no words per minute.
- **Gap**: the start of a cue minus the latest end of the earlier cues. An overlap gives a negative gap.
- **No cues**: all numbers are 0.

## iTunes Timed Text
Apple TV and the iTunes Store take subtitles as iTunes Timed Text (iTT). iTT is a TTML profile with SMPTE frame times.

```php
$subtitle = Subtitle::parse(file_get_contents('movie.itt'), IttParser::class);
$subtitle->getFormatData('itt');   // ['timeBase' => 'smpte', 'frameRate' => '24', 'frameRateMultiplier' => '999 1000', 'dropMode' => 'nonDrop']
$subtitle->format(IttFormatter::class);
Subtitle::parse($srt)->format(IttFormatter::class, [IttFormatter::OPTION_FRAME_RATE => 23.976]);
```

- **Parser**: `IttParser` is `TtmlParser` plus the `itt` format data. Format detection returns `TtmlParser` for an iTT file. Both read the same cues.
- **Frame rate**: the formatter takes it from the `itt` format data, else from `OPTION_FRAME_RATE`. It accepts 23.976, 24, 25, 29.97 and 30. Without one of these, it throws `InvalidArgumentException`. 23.976 becomes `ttp:frameRate="24" ttp:frameRateMultiplier="999 1000"`.
- **Times**: a time such as `00:00:01:12` is an SMPTE time code. It counts frames at the effective frame rate, so at 29.97 fps `01:00:00:00` is 3603.6 s. The formatter rounds each time to the nearest frame and gives each cue at least one frame. It always writes `ttp:dropMode="nonDrop"`.
- **Apple limits**: one `div`, `sansSerif` as the only font family, and a fixed `<head>` with the `normal` style and the `top` and `bottom` regions. Alignment 7, 8 and 9 go to `top`, all others to `bottom`. The formatter does not keep the `<head>` or the attributes of the input file.
- **Markup**: the formatter keeps `<b>`, `<i>`, `<u>` and `<font color>`. It writes a colour only as `#rrggbb` or a TTML colour name, and drops an alpha channel. It strips `<s>`, `<v>` and word timestamps.

## Dual subtitles
A dual subtitle shows two languages at the same time, for example for language learners. Most players show only one subtitle track, so both languages go into one file.

```php
$english = Subtitle::parse(file_get_contents('movie.en.srt'));
$german  = Subtitle::parse(file_get_contents('movie.de.srt'));

$dual = DualSubtitle::merge($english, $german, new DualSubtitleOptions(secondaryStyle: 'i'));
$dual = DualSubtitle::merge($english, $german, new DualSubtitleOptions(
    mode: DualSubtitleOptions::MODE_TOP_BOTTOM,     // English at the bottom, German at the top
    snapTolerance: 0.25,                            // seconds
    secondaryStyle: 'font color="#ffff00"',
    secondaryAlignment: 8,
));
```

| Mode | Result for `00:00:01.000 --> 00:00:04.000 Where are you going?` and `00:00:01.200 --> 00:00:03.900 Wohin gehst du?` | Formats |
|:--- |:--- |:--- |
| `stack`, the default | one cue from 1.000 s to 4.000 s with the lines `Where are you going?` and `<i>Wohin gehst du?</i>` | all |
| `topBottom` | the English cue with alignment `null`, and the German cue with alignment 8 from 1.000 s to 4.000 s | SubRip with `{\an8}`, WebVTT, ASS and TTML. The other formats do not write the alignment |

- **Stack**: each secondary cue joins the primary cue that it overlaps most. The joined cue spans from the earlier start to the later end. A cue without an overlap stays a cue of its own.
- **Top and bottom**: a secondary start or end time moves to the closest primary start or end time within `snapTolerance`. So the two languages appear and disappear together. A cue keeps its times when both would move to the same time.
- **Secondary style**: a core markup tag, such as `i` or `font color="#ffff00"`, around each secondary line. WebVTT has no font colour, so its formatter drops the `font` tag.
- **Copied data**: the result is a new `Subtitle`. Metadata, comments and format data come from the primary subtitle. The secondary cues lose their identifiers and format data. The language becomes `en+de` when both subtitles have a language.

## Removing hearing-impaired annotations
```php
$subtitle->removeHearingImpaired();         // '(laughs) You came back.' becomes 'You came back.'
$subtitle->removeHearingImpaired(new HearingImpairedOptions(
    speakerLabelsUpperCaseOnly: false,      // also removes 'Baker:' and 'Note:'
    customBrackets: [['{', '}'], ['*', '*']],
    lyrics: true,                           // removes '# The wheels go round #'
));
(new HearingImpairedOptions())->isHearingImpaired('JOHN: Hi.'); // true, the line stays unchanged
```

| Option | Default | Removes |
|:--- |:--- |:--- |
| `squareBrackets` | on | `[DOOR SLAMS]` |
| `parentheses` | on | `(laughs)` |
| `speakerLabels` | on | `JOHN:`, `MAN 2:` and `DR. O'NEIL:` at the start of a line or after its dash |
| `speakerLabelsUpperCaseOnly` | on | When off, `speakerLabels` also removes labels such as `Baker:`, and so also `Note:` |
| `musicOnlyLines` | on | Lines that hold only music notes U+2669 to U+266C or a separate `#` |
| `customBrackets` | none | Text between each pair, for example `{laughs}`. `{\an8}` stays, because a backslash after `{` marks an ASS override tag. |
| `lyrics` | off | Text between two music symbols, and lines that start or end with one |

- **Rules**: they follow the "Remove text for hearing impaired" tool of [Subtitle Edit](https://github.com/SubtitleEdit/subtitleedit/blob/5b9ee8baf08c472c7a74fbc337446b656dedabb4/src/libse/Forms/RemoveTextForHI.cs). Its interjection list and its "only separate lines" options are not available.
- **Visible text only**: the rules see the text between tags, with `&lt;`, `&gt;` and `&amp;` decoded. A bracket can span tags and lines. Tags stay, and a tag pair that becomes empty, such as `<i></i>`, goes.
- **Spaces**: the space next to a removed annotation goes too. `Wait (sighs) now.` becomes `Wait now.`
- **Empty lines and cues**: a line with only a dash left goes. A cue with no text left goes, and comments stay before the next cue.
- **Dialogue dashes**: when only one of two or more dash lines stays, its `- ` goes too. `- Is it open?` and `- (laughs)` become `Is it open?`.

## Comparing two subtitles
A translator delivers `episode1_v2.srt`. `SubtitleDiff` lists what changed against `episode1_v1.srt`. It pairs cues by time and text, not by cue number, so one added cue does not shift the rest.

```php
use SubtitleToolbox\Diff\CueDifference;
use SubtitleToolbox\Diff\SubtitleDiff;
use SubtitleToolbox\Diff\SubtitleDiffOptions;

$differences = SubtitleDiff::compare($v1, $v2);
$differences[0]->getKind();       // CueDifference::KIND_TEXT_CHANGED, "text changed"
$differences[0]->getOldIndex();   // 11, the key in $v1->getCues(), null for an added cue
$differences[0]->getNewIndex();   // 11, the key in $v2->getCues(), null for a removed cue
$differences[0]->getOldCue();     // the SubtitleCue in $v1
echo SubtitleDiff::toText($differences);

SubtitleDiff::compare($v1, $v2, new SubtitleDiffOptions(
    timeTolerance: 0.04,      // seconds, default 0.001. A larger difference is a timing change
    ignoreFormatting: true,   // compares the text without tags, with entities decoded
    ignoreWhitespace: true,   // compares the text without spaces, tabs and line breaks
    textOnly: true,           // reports no timing changes
));
SubtitleDiff::isEqual($a, $b);    // true when compare() finds no difference
```

`toText()` writes one block per difference, with cue numbers that start at 1:

```
text changed: old cue 12, new cue 12
- 00:00:39.000 --> 00:00:40.500
  I'll be their.
+ 00:00:39.000 --> 00:00:40.500
  I'll be there.
```

- **Kinds**: `added`, `removed`, `text changed`, `timing changed` and `text and timing changed`. Pairs without a change are not in the list.
- **Pairing**: two cues pair when their text is the same, when their text is nearly the same, or when they overlap for at least half of the shorter cue. Nearly the same means that the edit distance is at most 30 % of the longer text, counted in bytes. A weighted longest common subsequence keeps the pairs in order. Same text weighs most, then a time overlap. The idea comes from the Compare tool of [Subtitle Edit](https://github.com/SubtitleEdit/subtitleedit/blob/main/docs/features/compare.md).
- **Split and merged cues**: one half of a split cue pairs with the old cue as a text change. The other half is an added cue.
- **Moved cues**: a cue that moves past other cues is removed in one place and added in the other.
- **Speed**: 2,000 cues against 2,000 cues with 500 changes take about 0.03 s. A stretch of 200 changed cues against 200 changed cues takes about 0.2 s.
- **Limits**: cues with the same text split the files into stretches. In a stretch of more than 40,000 cue pairs, for example 250 cues against 250 cues, only cues that overlap in time pair. This happens when a translation is compared with its source.

## VobSub
VobSub is the subtitle format of DVD rips. It is a pair of files. The `.idx` text file holds the palette, the screen size, the tracks and their timestamps. The `.sub` file is an MPEG-2 program stream. It holds one **unit** (subpicture unit) per cue: a bitmap and the commands that show and hide it.

```php
use SubtitleToolbox\Parsers\VobSubParser;
use SubtitleToolbox\Subtitle;

$idx      = file_get_contents('movie.idx');
$subtitle = (new VobSubParser($idx))->parse(file_get_contents('movie.sub'));         // first track
$subtitle = (new VobSubParser($idx, 'de'))->parse(file_get_contents('movie.sub'));   // first track with "id: de"
$subtitle = (new VobSubParser($idx, 1))->parse(file_get_contents('movie.sub'));      // track with "index: 1"

$subtitle->getMetadata(Subtitle::METADATA_LANGUAGE);   // "de", from the id line
$subtitle->recognizeText(new TesseractEngine(), 'deu');
```

- **Cues**: every cue is an image cue without text. See [Image cues and OCR](#image-cues-and-ocr). The image has the size and the position of the display area of the unit, on a screen of the `.idx` size. A unit with the forced start command sets `forced`.
- **Times**: a cue starts at its `timestamp`, plus the `delay` lines of its track, plus the start delay of the unit. It ends at the stop delay of the unit. A unit without a stop command ends at the next unit, at most 5 s later.
- **Colors**: the unit picks 4 of the 16 `.idx` palette colors and sets their alpha. A `custom colors: ON` line replaces both with its 4 colors, and `tridx` marks the transparent ones.
- **Parse**: call the parser directly. `Subtitle::parse()` creates the parser without arguments, so it cannot pass the `.idx` content. For the same reason, format detection does not know VobSub.
- **Limits**: the parser reads one image per unit. Color and contrast changes after the start command, and the `CHG_COLCON` command, do not apply. The parser ignores the `org`, `scale`, `align`, `fadein/out` and `time offset` player settings.
- **Spec**: [DVD subtitles](http://sam.zoy.org/writings/dvd/subtitles/), [DVD sub-pictures](http://dvd.sourceforge.net/dvdinfo/spu.html) and the FFmpeg [decoder](https://github.com/FFmpeg/FFmpeg/blob/master/libavcodec/dvdsubdec.c) and [demuxer](https://github.com/FFmpeg/FFmpeg/blob/master/libavformat/mpeg.c).

## Finding cues
```php
count($subtitle);                               // 612
foreach ($subtitle as $index => $cue) { }       // in index order

$subtitle->getCuesAt(83.2);                     // [41 => $cue], the cues on screen at 83.2 s
$subtitle->getCueIndexAt(83.2);                 // 41, or null when no cue is on screen
$subtitle->getCuesBetween(600, 660);            // the cues that overlap 600 s to 660 s, not cut
$subtitle->findCues(fn (SubtitleCue $cue) => str_contains($cue->getText(), 'Paris'));
$subtitle->filterCues(fn (SubtitleCue $cue) => $cue->getEnd() - $cue->getStart() >= 0.5);   // removes the other cues
```

- **On screen**: a cue is on screen at time `t` when `start <= t < end`. A cue from 4.0 s to 6.0 s is on screen at 4.0 s, but not at 6.0 s. A cue with the same start and end is never on screen.
- **Overlaps**: cues can overlap, so `getCuesAt()` returns an array. `getCueIndexAt()` returns the lowest index of these cues.
- **Keys**: `getCuesAt()`, `getCuesBetween()` and `findCues()` keep the cue index as the array key.
- **Speed**: when the cues are in start order, `getCuesAt()` and `getCuesBetween()` use binary search. On 10,000 cues, a call takes about 0.6 ms instead of 2 ms. The lookup sees changes to cue times without a call to `reIndexCues()`.
- **Filter**: `filterCues()` moves a comment before a removed cue to the next kept cue, and then calls `reIndexCues()`.
- **No array access**: `$subtitle[3]` does not work. Use `getCues()`, `addCue()` and `removeCue()`, so the cue indexes and comments stay correct.

## PGS
Blu-ray discs and many MKV files store subtitles as PGS bitmaps in `.sup` files. `PgsParser` reads them as image cues, so run OCR before you write a text format.

```php
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::parse(file_get_contents('movie.sup'));                     // detects PGS
$subtitle = (new PgsParser(3.0))->parse(file_get_contents('movie.sup'));         // the last cue lasts 3 s, not 5 s
$subtitle->recognizeText(new TesseractEngine(), 'eng');
file_put_contents('movie.srt', $subtitle->format(SubRipFormatter::class));
```

- **Cues**: each display set that shows objects gives one cue. It starts at the time stamp of its composition segment and ends at the next one. A display set that repeats the same image does not start a new cue.
- **Last cue**: a last cue that no later display set ends lasts 5 s. Pass another duration in seconds to the constructor.
- **Image**: one PNG covers all objects of the display set on a transparent background. The parser applies cropping, windows and palette updates.
- **Colors**: the parser converts the palette with the BT.709 matrix for video higher than 576 lines, and with BT.601 for SD video, as FFmpeg does.
- **Forced**: `forced` in the image data is true when at least one object of the display set has the forced flag.
- **Alignment**: an image whose center is in the top third of the screen gets alignment 8. Other cues keep the default.
- **Errors**: segments of unknown types are skipped. `parse()` throws `ParsingException` for a segment without the `PG` bytes, a cut-off segment, and a bitmap with too few pixels.
- **Speed**: a 1,500-cue file of 640x90 images takes about 18 s on PHP 8.5. The PNG compression takes most of this time.
- **Spec**: [PGS segments](http://blog.thescorpius.com/index.php/2017/07/15/presentation-graphic-stream-sup-files-bluray-subtitle-format/), the patent application [US 2009/0185789 A1](https://patents.google.com/patent/US20090185789A1/en) and the FFmpeg [decoder](https://github.com/FFmpeg/FFmpeg/blob/master/libavcodec/pgssubdec.c).

## JSON, arrays and plain text transcripts
A web app stores the cues in a database and sends them to the browser as JSON.

```php
use SubtitleToolbox\Formatters\JsonFormatter;
use SubtitleToolbox\Formatters\PlainTextFormatter;
use SubtitleToolbox\Parsers\JsonParser;

$array = $subtitle->toArray();                     // toArray(false) leaves out the format data
$copy  = Subtitle::fromArray($array);              // equal to $subtitle
$json  = $subtitle->format(JsonFormatter::class, [JsonFormatter::OPTION_PRETTY_PRINT => true]);
$copy  = Subtitle::parse($json, JsonParser::class);
$text  = $subtitle->format(PlainTextFormatter::class);
```

`JsonFormatter` writes this shape. `toArray()` returns the same shape as a PHP array, with binary strings as they are.

```json
{
    "version": 1,
    "metadata": {"title": "Big Buck Bunny", "language": "en"},
    "comments": [{"text": "Translated by Jane Doe", "beforeCueIndex": 0}],
    "formatData": {"ass": {"scriptInfo": {"PlayResX": "1920"}}},
    "cues": [
        {"start": 1.5, "end": 4.0, "lines": ["Hello", "<i>world</i>"], "identifier": "intro", "alignment": 8, "formatData": {}},
        {"start": 5.0, "end": 6.5, "lines": [], "identifier": null, "alignment": null,
         "formatData": {"image": {"png": {"base64": "iVBORw0KGgo..."}, "x": 640, "y": 940, "width": 2, "height": 1,
                                  "screenWidth": 1920, "screenHeight": 1080, "forced": false}}}
    ]
}
```

| Field | Type | Required | Content |
|:--- |:--- |:--- |:--- |
| `version` | integer | yes | 1. A later version of the shape gets a new number. `fromArray()` rejects all other numbers |
| `metadata` | object of strings | no | the keys of `getAllMetadata()` |
| `comments` | list of objects | no | `text` and `beforeCueIndex`, as `getComments()` returns them |
| `formatData` | object of objects | no | the format data of the subtitle by format key |
| `cues` | list of objects | yes | the cues in this order. `fromArray()` does not sort them |
| `cues[].start`, `cues[].end` | number | yes | seconds, rounded to milliseconds |
| `cues[].lines` | list of strings | yes | the lines with core markup and escaped `&lt;`, `&gt;` and `&amp;` |
| `cues[].identifier` | string or null | no | the cue identifier |
| `cues[].alignment` | integer or null | no | 1 to 9 in numeric keypad layout |
| `cues[].formatData` | object of objects | no | the format data of the cue by format key |

- **Binary data**: `JsonFormatter` writes each format data string that is not valid UTF-8 as `{"base64": "..."}`. The PNG of an image cue is such a string. `JsonParser` decodes every object in the format data that has `base64` as its only key.
- **Errors**: `JsonParser` and `fromArray()` throw `ParsingException` with the path of the bad field, for example `The field cues[3].start must be a number.`
- **Text**: cue lines and metadata must be UTF-8. Otherwise `JsonFormatter` throws `JsonException`. Parse a file in another encoding with its source encoding, see "Encodings and line endings".
- **Options**: `OPTION_PRETTY_PRINT` indents with 4 spaces and ends with a newline. `OPTION_WITH_FORMAT_DATA => false` leaves out the format data. The output options `lineEnding` and `bom` work as in the other formatters.
- **Detection**: an object with a numeric `version` key and a `cues` list detects as `JsonParser`. Detection fails when more than about 70,000 cues come before the `version` key. Then pass `JsonParser::class`. `JsonFormatter` writes `version` first.

`PlainTextFormatter` writes a transcript. It strips all tags and decodes the entities. A gap of 2 s or more between two cues starts a new paragraph:

```
Hello world. Where are you going?

Home.
```

| Option | Default | Effect |
|:--- |:--- |:--- |
| `OPTION_JOIN_LINES` | `true` | joins the lines of a cue with a space. `false` writes each line on its own line |
| `OPTION_JOIN_CUES` | `true` | joins the cues of a paragraph with a space. `false` writes each cue on its own line |
| `OPTION_PARAGRAPH_GAP` | `2.0` | the gap in seconds that starts a new paragraph. `INF` writes one paragraph |
| `OPTION_WITH_TIMES` | `false` | writes the start of the paragraph as `[00:01:23] ` before it |

- **Gap**: the start of a cue minus the latest end of the earlier cues.
- **Cues without text**: the formatter skips them. Image cues without text need `OPTION_SKIP_IMAGE_CUES`, as in all text formatters.

## SubViewer
SubViewer 1 and 2 are `.sub` formats from older DivX releases and DVD rippers. One parser reads both versions.

```php
$subtitle = Subtitle::parse(file_get_contents('movie.sub'), SubViewerParser::class);
$subtitle->getFormatData('subviewer');   // ['version' => 2, 'header' => ['DELAY' => '0', 'CD TRACK' => '0'], 'style' => '[COLF]&HFFFFFF,[STYLE]bd,[SIZE]18,[FONT]Arial']
$subtitle->format(SubViewerFormatter::class);                                            // SubViewer 2
$subtitle->format(SubViewerFormatter::class, [SubViewerFormatter::OPTION_VERSION => 1]); // SubViewer 1
```

- **Version**: a `******** START SCRIPT ********` line makes a file SubViewer 1. All other files are SubViewer 2.
- **Header**: `[TITLE]` and `[AUTHOR]` become the metadata keys `title` and `author`. The parser keeps the other header tags and the `[COLF]` style line in the `subviewer` format data. The formatter writes them back after `[TITLE]` and `[AUTHOR]`. For a subtitle from another format, it writes the tags that Subtitle Edit writes.
- **Delay**: the parser adds the SubViewer 1 `[DELAY]` seconds to every time, as FFmpeg does. It stores `[DELAY]` as 0. It keeps the SubViewer 2 `[DELAY]` value and does not apply it.
- **SubViewer 2 text**: `[br]` and each text line become a cue line. The formatter writes all lines of a cue on one line, joined by `[br]`. The parser accepts one to three digits after the dot, as FFmpeg does.
- **SubViewer 1 cues**: a `[00:00:01]` line with text below starts a cue. A time line with an empty line below ends the cue before it. A cue without such an end line ends where the next cue starts. The last cue lasts 10 s unless you pass `lastCueDuration` to the parser. `|` is a line break. As in FFmpeg, the parser reads only the first text line after a time line.
- **Cues without text**: the formatter skips them, because an empty line ends a cue.

## EBU STL
```php
$subtitle = Subtitle::parse(file_get_contents('news.stl'));                   // detects EbuStlParser
$subtitle = (new EbuStlParser(true))->parse(file_get_contents('news.stl'));   // cue times minus the start of programme
$subtitle->getFormatData('stl')['gsi']['TCP'];                                // '10000000'
$subtitle->format(EbuStlFormatter::class, [EbuStlFormatter::OPTION_FRAME_RATE => 30]);
```

- **Spec**: [EBU Tech 3264](https://tech.ebu.ch/docs/tech/tech3264.pdf). The file is binary, so pass its bytes unchanged.
- **Times**: the disk format code `STL25.01` or `STL30.01` sets the frame rate. By default, the parser keeps the time codes of the file.
- **Start of programme**: `new EbuStlParser(true)` subtracts the TCP time code, for example `10:00:00:00`. A time before it becomes 0. The formatter adds TCP again.
- **Characters**: the parser reads the character code tables 00 (ISO 6937) and 01 to 04 (ISO 8859-5, -6, -7 and -8). `Encoding\Iso6937` and `Encoding\CodePage` hold the tables, so the library needs no `mbstring` or `iconv`.
- **ISO 6937**: byte `24h` is the currency sign and byte `A4h` is the dollar sign. An accent byte comes before its letter. The formatter writes `?` for a character outside the table.
- **Codes**: `80h` to `83h` become `<i>` and `<u>`. The teletext colour codes `00h` to `07h` become `<font color>`. White gives no tag. Other teletext codes become a space.
- **Colours**: the formatter writes only the 8 teletext colours, for example `#ff0000` as `01h`. It drops other colours.
- **Blocks**: the parser joins the TTI blocks with the same subtitle number and skips user data blocks. A subtitle with the comment flag becomes a comment.
- **Extension blocks**: the formatter splits text of more than 111 bytes into extension blocks. The last text field always ends with `8Fh`.
- **Alignment**: the justification code gives the column. `00h` and `02h` are centred. The vertical position gives the row: the top, middle or bottom third of the rows. Teletext has the rows 1 to 23. Open subtitles have the rows 0 to MNR.
- **Metadata**: the title is the OPT field. The language is the LC field, for example `09` is `en`.
- **Format data**: the subtitle keeps the GSI fields by mnemonic in `gsi`, for example `DSC` and `TCP`. Each cue keeps `subtitleGroupNumber`, `cumulativeStatus`, `verticalPosition`, `justificationCode` and its original blocks.
- **Round trip**: an unchanged file comes out byte for byte. A cue with unchanged text keeps its text field bytes, also after retiming. The formatter writes the stored position while it gives the cue alignment.
- **Counts**: the formatter computes TNB, TNS, TNG and TCF. It numbers the subtitles in order, from the first stored subtitle number.
- **New files**: a subtitle from another format gets code page 850, 25 fps, level-1 teletext, table 00, 40 characters, 23 rows and subtitle numbers from 1. The creation date is today.

## Errors
Every exception of the library implements `SubtitleToolbox\Exceptions\SubtitleToolboxException`. One `catch` block handles all of them, and lets errors from other code pass.

```php
use SubtitleToolbox\Exceptions\SubtitleToolboxException;

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

- **Old catch blocks**: each class extends the SPL class that the library threw before. A `catch (\InvalidArgumentException $e)` or `catch (\RuntimeException $e)` still works.
- **Messages**: the first four classes start the message with the class name and the code, for example `ParsingException (Error #100): `. The last two keep the plain message.
- **Line number**: `ParsingException::getLineNumber()` returns the 1-based input line when the parser knows it, and null otherwise. Then the message ends with ` (line 12)`. The ASS, MicroDVD, MPSub and SubViewer parsers set it.
- **Codes**: a new exception class takes the next free code after 105.

## Scenarist Closed Captions
US broadcast and many streaming services take closed captions as SCC. Each line of an SCC file is a time code and CEA-608 byte pairs, one pair per frame at 29.97 fps.

```php
$subtitle = Subtitle::parse(file_get_contents('show.scc'));              // detects SccParser
$subtitle->getFormatData('scc');                                         // ['dropFrame' => true]
$subtitle->getCues()[0]->getFormatData('scc');                           // ['mode' => 'pop-on', 'rows' => [14, 15], 'columns' => [4, 8]]
(new SccParser(2))->parse($content);                                     // data channel 2, CC2 or CC4

$subtitle->wrapLines(32, 4)->format(SccFormatter::class);
$subtitle->format(SccFormatter::class, [SccFormatter::OPTION_DROP_FRAME => false]);
```

- **Decoder**: the parser follows the screen model of [47 CFR 15.119](https://www.govinfo.gov/content/pkg/CFR-2010-title47-vol1/xml/CFR-2010-title47-vol1-sec15-119.xml). Each change of the displayed captions starts a new cue. Paint-on and roll-up data on one line of the file give one cue. So a roll-up file gives one cue per screen, and a row shows in each cue until it rolls off.
- **Times**: a semicolon before the frames marks drop-frame time code, a colon marks non-drop time code. Both count frames at 30000/1001 fps. Each byte pair takes one frame, so a code later on the line acts later. A caption that no command erases ends 4 s after its start, as in [pycaption](https://github.com/pbs/pycaption).
- **Damaged data**: 47 CFR 15.119 (i) and (j) define the rules. The parser ignores the second copy of a doubled control code. It drops a byte with a parity error, where a television shows a solid block. It reads the lines in time order. It skips data channel 2, XDS packets and text mode.
- **Position**: rows 1 to 4 give alignment 8, and all other rows give `null`. The `scc` format data keeps the row and column of each line. The formatter writes them back when they still fit the cue. Else it places the lines by the alignment, at the bottom and centred by default.
- **Timing of the writer**: the formatter loads each caption before the cue start, so that EOC falls on the first frame of the cue. When the frames after the previous caption are too few for the load, the caption shows late. An EDM erases the caption at its end, unless the next caption replaces it. Of two overlapping cues, the later one replaces the earlier one.
- **Markup**: PAC styles and mid-row codes become `<i>`, `<u>` and `<font color>` with `#ffffff`, `#00ff00`, `#0000ff`, `#00ffff`, `#ff0000`, `#ffff00` and `#ff00ff`. The formatter writes other colours as white. A mid-row code takes the place of one character. The formatter puts it on the space before a style change. A style change inside a word adds a space.
- **Characters**: the standard, special and extended sets of CEA-608. The formatter writes an extended character after a standard fallback character, for decoders without the extended set. For a character outside the sets, it throws `InvalidArgumentException`.

## Streaming large SRT and WebVTT files
`Subtitle::parse()` keeps the whole file and one object per cue in memory. An 80 MB SRT file with 400,000 cues does not fit into the default `memory_limit` of 128 MB. The stream readers and writers handle one cue at a time.

```php
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\WebVttStreamWriter;

$writer = new WebVttStreamWriter(fopen('show.vtt', 'wb'));     // or a file path
foreach ((new SubRipStreamReader())->read('show.srt') as $cue) {     // or a stream resource
    $writer->write($cue->setStart($cue->getStart() + 2)->setEnd($cue->getEnd() + 2));
}
$writer->close();
```

- **Formats**: only SubRip and WebVTT, because their cue blocks do not depend on each other. Other formats need the whole file, for example for LRC end times or ASS headers.
- **Same result**: the readers parse each cue block with the method of `SubRipParser` and `WebVttParser`, and the writers use the method of `SubRipFormatter` and `WebVttFormatter`. Cues and output bytes are the same as in the batch classes, with the same options.
- **Order**: the readers yield cues in file order. They do not sort the cues by start time, as `Subtitle` does.
- **Errors**: a reader throws `ParsingException` at the first block that `parse()` rejects. It has yielded the cues before that block. A path that does not open, a write to a closed writer, and a stream that rejects writes throw `InvalidArgumentException`.
- **Memory**: 200,000 cues in a 14.7 MB file take less than 1 MB of memory to read or write. The read takes about 3 s on PHP 8.5. A line ends at LF, CR LF or CR CR LF. A file with only CR line endings is one line for `fgets()`, so it takes memory for the whole file.
- **WebVTT header**: `WebVttStreamReader::getHeader()` returns the header text, the header lines, and the `STYLE` and `REGION` blocks after the first cue. Pass this array to the `WebVttStreamWriter` constructor.
- **Comments**: `WebVttStreamReader` skips `NOTE` blocks. `WebVttStreamWriter` writes no comments.
- **Encoding**: the readers accept UTF-8, with or without a BOM, and keep the bytes of other 8-bit encodings. For UTF-16, add a filter: `stream_filter_append($in, 'convert.iconv.UTF-16/UTF-8')`.
- **Closing**: `close()` flushes the stream. It closes the stream only when the writer opened it from a file path.

## Whisper JSON
A speech-to-text tool based on OpenAI Whisper writes a JSON transcript. The parser turns it into cues for SubRip or WebVTT.

```php
$subtitle = Subtitle::parse($openAiResponseBody);                          // detects WhisperJsonParser
$parser   = new WhisperJsonParser([WhisperJsonParser::OPTION_WORD_TIMESTAMPS => true]);
$subtitle = $parser->parse(file_get_contents('lecture.json'));
$subtitle->getCues()[0]->getText();                                          // '<00:00:00.000>The <00:00:00.240>beach was quiet.'
$subtitle->getCues()[0]->getFormatData('whisper')['avg_logprob'];            // -0.25
```

| Tool | Shape | Source |
|:--- |:--- |:--- |
| OpenAI API | `response_format=verbose_json`. `segments`, and `words` at the top level with `timestamp_granularities[]=word` | [API reference](https://platform.openai.com/docs/api-reference/audio/createTranscription), [`TranscriptionVerbose`](https://github.com/openai/openai-python/blob/e5de2e5656fb3d4fa70f050195382e6a4d59f806/src/openai/types/audio/transcription_verbose.py) |
| openai-whisper | `--output_format json`. `segments`, with `words` per segment with `--word_timestamps True` | [`transcribe.py`](https://github.com/openai/whisper/blob/86098128c0b4f24f0e2aa2994de830614b474227/whisper/transcribe.py) |
| faster-whisper | `segments` with the fields of the `Segment` class. whisper-ctranslate2 writes them as openai-whisper does, with `"words": null` without word timestamps | [`transcribe.py`](https://github.com/SYSTRAN/faster-whisper/blob/7b99be5376b41cd481dfc52e8caa00a497c3294e/faster_whisper/transcribe.py), [whisper-ctranslate2](https://github.com/Softcatala/whisper-ctranslate2/blob/7c06913255bea6f630b6d1357e99dc0c3bfa1819/src/whisper_ctranslate2/transcribe.py) |
| WhisperX | `segments` with `words` that have `score` and, after diarization, `speaker` | [`alignment.py`](https://github.com/m-bain/whisperX/blob/771b4a14a9486f8fd5aef18ef49e35d639523dd3/whisperx/alignment.py) |
| whisper.cpp | `-oj`: `transcription` with `offsets` in milliseconds. `-ojf` adds `tokens` | [`cli.cpp`](https://github.com/ggml-org/whisper.cpp/blob/60c0be6ac8fa71b1a2ae2dd938a31a34a508e774/examples/cli/cli.cpp) |

- **Cues**: one cue per segment. The parser trims the text and skips segments without text. A long segment stays one cue. `wrapLines()` and `splitCue()` break it up.
- **Word timestamps**: off by default. With `OPTION_WORD_TIMESTAMPS`, each word that has a start time and occurs in the segment text gets a core word timestamp before it. The parser finds the words in order and skips the others.
- **API words**: the API lists the words of all segments at the top level. A word goes to the segment that holds the middle of the word.
- **whisper.cpp tokens**: a token with a leading space starts a new word, as `--split-on-word` does. The parser skips special tokens such as `[_BEG_]`.
- **Language**: the `language` metadata. A name such as `english` becomes `en`. A code such as `en` stays. whisper.cpp gives it in `result.language`.
- **Format data**: the subtitle keeps all top-level fields in `getFormatData('whisper')` except `segments`, `transcription`, `words`, `word_segments` and `text`. Examples are `duration` from the API and `model.type` from whisper.cpp.
- **Cue format data**: each cue keeps the fields of its segment except the times and the text. Examples are `avg_logprob`, `no_speech_prob`, `words` with `probability` or `score`, `speaker` and the whisper.cpp `tokens` with `p`.
- **Errors**: JSON without a `segments` or `transcription` list throws `ParsingException`. A response with only `words` or `text` has no cue times, so it throws too.

## Lenient parsing
A subtitle download is often broken in one place. By default, the parsers throw `ParsingException` at the first broken block. In lenient mode, the parsers in the two tables below skip or repair the broken block, record a `ParseWarning` and go on.

```php
use SubtitleToolbox\Parsers\SubRipParser;

$parser   = (new SubRipParser())->setLenient();
$subtitle = Subtitle::parse($download, $parser);     // a parser instance in place of the class name
foreach ($parser->getWarnings() as $warning) {
    $logger->warning("line $warning->lineNumber: $warning->message ($warning->action)");
}
// line 5: Block #1 doesn't seem to have its timestamps on its second line! (skipped)
```

| Damage | SubRip | WebVTT | SBV |
|:--- |:--- |:--- |:--- |
| cue without a cue number | repaired | not an error | not an error |
| bad timestamp, `->` arrow, cue without text | skipped | skipped | skipped |
| no empty line between two cues | repaired | split as the spec says, no warning | repaired |
| no empty line after the `WEBVTT` header | not an error | repaired | not an error |
| text before the first cue | skipped | skipped | skipped |
| truncated last cue | skipped | skipped | skipped |

| Parser | Skipped with a warning | `blockIndex` counts |
|:--- |:--- |:--- |
| ASS, SSA | a `Dialogue:` or `Comment:` line with too few fields or a bad time. A file without a `Format:` line is not an error: the parser uses the default fields. | events |
| MicroDVD | a line without `{start}{end}` frames, also before the `{1}{1}<fps>` line | non-empty lines |
| SubViewer | SubViewer 2: a timing line with one bad time and its text, and text before the first cue. SubViewer 1: a bad header line. | cues |
| MPSub | a bad wait and duration pair and its text, a cue without text, a bad `FORMAT=` value. A file without `FORMAT=` gets a `repaired` warning, and the parser reads the times as seconds. | cues |
| LRC | a line with a time tag that the parser cannot read, for example `[01:2x.00]` | non-empty lines |
| SAMI | a `<SYNC>` tag without a valid `Start` | `<SYNC>` tags |
| TTML, iTT | a `<p>` with a bad time or without an end time | `<p>` elements |
| EBU STL | a subtitle with a time code out of range, a cut-off last TTI block | TTI blocks |
| JSON | a cue with a bad field | cues |
| Whisper JSON | a segment without `start`, `end` or `text` | segments |

- **Default**: strict mode. Cues, exceptions and messages stay as they were.
- **`ParseWarning`**: `message`, the 1-based `lineNumber`, the 0-based `blockIndex` of the "Block #n" messages or of the units in the table, the trimmed lines of the `block`, and the `action`, `ParseWarning::SKIPPED` or `ParseWarning::REPAIRED`. A skipped block reports its first line. A repair reports the line where the parser split or read the cue.
- **Warnings**: `getWarnings()` returns the warnings of the last `parse()` call. Each call starts with an empty list.
- **Not the format**: lenient mode still throws for a WebVTT file without `WEBVTT`. SubRip and SBV have no signature, so a file without one readable cue gives no cues and warnings.
- **Whole-file errors**: lenient mode still throws for a problem outside one cue. Examples are invalid XML in TTML, invalid JSON, a SAMI file that is not UTF-8, an ASS file without `[Events]` and a MicroDVD file without a frame rate.
- **No line numbers**: EBU STL is binary and JSON has no line numbers after decoding. Their warnings have `lineNumber` 0.
- **Strict mode without an exception**: the LRC parser drops a line with a bad time tag, and the EBU STL parser reads a time code out of range as it is. In lenient mode, both record a warning, and the EBU STL parser also skips the subtitle.
- **`Subtitle::parse()`**: pass a parser instance to keep its mode and read its warnings after the call. The encoding conversion and the `sourceEncoding` argument work as with a class name. A class name parses in strict mode. Format detection returns a class name, so call `Subtitle::detectParser()` first to detect and parse leniently.
- **Stream readers**: `SubRipStreamReader` and `WebVttStreamReader` have the same `setLenient()` and `getWarnings()`. They use the block methods of `SubRipParser` and `WebVttParser`, so a file gives the same cues and warnings as in the batch parser. During the read, `getWarnings()` holds the warnings of the blocks read so far.
- **Other parsers**: the SCC, PGS and VobSub parsers ignore `setLenient()` and throw as before.

## Command line tool
Composer installs the tool as `vendor/bin/subtitle-toolbox`. It needs no package beyond the library.

```sh
vendor/bin/subtitle-toolbox convert movie.srt movie.vtt
vendor/bin/subtitle-toolbox convert season1/ --to vtt --output-dir out/ --keep-going
vendor/bin/subtitle-toolbox convert movie.sub movie.srt --fps 23.976
vendor/bin/subtitle-toolbox shift movie.srt --by -2.5 --output movie.fixed.srt
vendor/bin/subtitle-toolbox fps *.srt --from 25 --to 23.976 --in-place
vendor/bin/subtitle-toolbox validate movie.srt --preset netflix-en --json
curl -s https://example.com/movie.srt | vendor/bin/subtitle-toolbox convert - --to vtt > movie.vtt
```

| Command | Does |
|:--- |:--- |
| `convert` | writes each input in the format of `--to` or of the output file extension |
| `shift`, `scale`, `fps` | call `shift()`, `scale()` and `convertFrameRate()`. `sync-fps` is another name for `fps` |
| `fix` | calls `fixOverlaps()`, `extendShortCues()`, `wrapLines()`, `unwrapLines()` and `removeDuplicateCues()` |
| `strip-sdh` | calls `removeHearingImpaired()` |
| `info` | prints the format and the statistics, as text or with `--json` |
| `validate` | prints each broken rule, as text or with `--json` |
| `formats` | lists the format names and extensions |

- **Help**: `subtitle-toolbox help convert` or `subtitle-toolbox convert --help` lists the options of a command.
- **Inputs**: a file, a directory, a glob such as `"season1/*.srt"`, or `-` for standard input. A directory gives its files with a known extension.
- **Input format**: `--from`, else format detection on the content, else the file extension. `.sub` is MicroDVD.
- **Output**: `--output` for one file, `--output-dir`, or `--in-place`. `--output -` writes standard output. Without these, `convert` writes next to the input with the new extension, and the other commands write standard output.
- **Overwrite**: the tool never overwrites a file without `--force` or `--in-place`.
- **Batch**: the tool prints one line per file and a summary. It stops at the first failed file, unless you pass `--keep-going`.
- **Exit code**: 0 when all files succeed, 1 when a file fails or breaks a validation rule, 2 for invalid arguments.
- **Version**: `subtitle-toolbox --version` prints the installed release, for example `1.40.0`, or `dev` in a Git checkout.

## Built-in OCR
`GlyphOcrEngine` reads the bitmaps of PGS and VobSub cues in pure PHP. It uses the optional package [yama6a/php-glyph-ocr](https://github.com/yama6a/php-glyph-ocr), a port of the nOCR engine of Subtitle Edit.

```sh
composer require yama6a/php-glyph-ocr:^0.1
```

```php
$subtitle = Subtitle::parse(file_get_contents('movie.sup'));            // PGS, image cues
$subtitle->recognizeText(new GlyphOcrEngine());                        // Latin database by default
file_put_contents('movie.srt', $subtitle->format(SubRipFormatter::class));
```

- **Package**: without php-glyph-ocr, `new GlyphOcrEngine()` throws `InvalidArgumentException` with the `composer require` command.
- **Database**: the first argument is a `GlyphOcr\GlyphDatabase`. The default is the Latin database of Subtitle Edit. It takes about 50 MB of memory, so engines that exist at the same time share one copy.
- **Options**: the second argument holds named arguments of `GlyphOcr\Recognizer`, for example `['italicSlant' => 0.2]`. An unknown name or an invalid value throws `InvalidArgumentException`.
- **One engine per stream**: the engine keeps one recognizer for all cues. The recognizer learns the glyph heights from the cues it reads, so use a new engine for each subtitle stream.
- **Italic**: a word becomes italic when most of its characters match italic glyphs. Italic words next to each other share one `<i>` run.
- **Language**: the engine ignores the language argument. The database sets the characters it knows.
- **Confidence**: the `OcrResult` confidence is the mean confidence of the glyphs of the cue. A glyph that matches nothing reads as `*` with confidence 0.
- **Limits**: the text must have one color on a transparent or dark background. Glyphs with the same shape, such as I and l in sans-serif fonts, come out as the one the database has first. There is no dictionary that fixes OCR errors.

### Accuracy
| Images | Characters | Lines without error |
|:--- | ---:| ---:|
| php-glyph-ocr test images, DejaVu Sans and Liberation Sans, 20 to 60 px, Latin database | 79.1% | 5 of 41 |
| The same images after training | 94.6% | 19 of 41 |
| `tests/files/pgs/text_1080p.sup`, Liberation Sans, 44 to 60 px | 97.1% | 8 of 16 |
| `tests/files/vobsub/text-pal.sub`, Liberation Sans, 24 to 30 px, 4 colors | 69.0% | 0 of 8 |

- Character accuracy is 1 minus the edit distance divided by the length of the drawn text.
- The Latin database holds neither font. Small DVD text reads worst. Training adds glyphs of the font of your file and fixes most errors.
- The tests fail when a fixture set reads below its number in this table. The `.ocr.srt` file next to each fixture holds the expected output.

### Speed
OCR of a 1,500-cue PGS file of 1080p text in 1 or 2 lines, with the Latin database, on one core of an x86_64 machine:

| | PHP 8.2 | PHP 8.5 |
|:--- | ---:| ---:|
| OCR | 163 s | 110 s |
| OCR per cue | 109 ms | 73 ms |
| Parse the file first | 26 s | 16 s |
| Peak memory | 102 MB | 139 MB |

- Raise `memory_limit` above the default 128 MB for a long file. The database and the PNG images of all cues stay in memory.
- A trained database is faster. php-glyph-ocr reads its test images in 92 ms each after training, and in 280 ms with the Latin database.

### Training a database
Train a glyph from a sample that a person confirmed. Then save the database and pass it to the engine:

```php
use GlyphOcr\GlyphDatabase;
use GlyphOcr\Image;
use GlyphOcr\Recognizer;
use GlyphOcr\Trainer;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\GlyphOcrEngine;

$database   = GlyphDatabase::latin();
$recognizer = new Recognizer($database);
$trainer    = new Trainer();
foreach ($subtitle->getCues() as $cue) {
    $result = $recognizer->recognize(Image::fromPng(CueImage::fromCue($cue)->png));
    foreach ($result->unknownChars() as $char) {
        $database->add($trainer->train($char->sample, askAPerson($char->sample->toAscii())));
    }
}
$database->save('my-font.nocr');

$subtitle->recognizeText(new GlyphOcrEngine(GlyphDatabase::fromFile('my-font.nocr')));
```

- `askAPerson()` is your own code. It shows the glyph and returns the text that a person types.
- A new glyph goes first, so it wins over older glyphs that match equally well. Train a misread character the same way, from `$result->lines[$line]->chars[$index]->sample`.
- The [php-glyph-ocr README](https://github.com/yama6a/php-glyph-ocr#databases-and-training) describes the `.nocr` files, `Recognizer::split()` and `GlyphSample::merge()`.

## OCR in the command line tool
`convert --ocr` reads the image cues of PGS and VobSub files with `GlyphOcrEngine` before it writes the output. It needs the package php-glyph-ocr, see [Built-in OCR](#built-in-ocr).

```sh
vendor/bin/subtitle-toolbox convert movie.sup movie.srt --ocr
vendor/bin/subtitle-toolbox convert movie.idx movie.srt --ocr --ocr-database my-font.nocr
```

- **Database**: `--ocr-database` loads a `.nocr` file in place of the Latin database. See [Training a database](#training-a-database).
- **Missing package**: without php-glyph-ocr, `--ocr` stops with exit code 2 and prints the `composer require` command.
- **Progress**: the tool prints `movie.sup: OCR 100/1500` to standard error after every 100 image cues and after the last one.
- **Memory**: a 1,500-cue PGS file needs up to 139 MB, above the default `memory_limit` of 128 MB. Run `php -d memory_limit=512M vendor/bin/subtitle-toolbox convert movie.sup movie.srt --ocr` for long files.
- **Info**: `info` prints `Image cues: 12, 0 with text` for a file with image cues. The JSON holds `"imageCues": {"count": 12, "withText": 0}` for every file.

## Shot changes and gaps
A shot change is the frame where the picture cuts to a new shot. `ShotChangeTiming` times cues to the shot changes and closes small gaps, as the [Netflix Subtitle Timing Guidelines](https://partnerhelp.netflixstudios.com/hc/en-us/articles/360051554394) require. You pass the shot change times. The library does not read video.

```php
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Timing\ShotChanges;
use SubtitleToolbox\Timing\ShotChangeTiming;

// ffmpeg -i in.mp4 -vf "select='gt(scene,0.3)',showinfo" -f null - 2> scenes.log
$shotChanges = ShotChanges::fromFfmpegLog(file_get_contents('scenes.log'));   // [12.5, 62.5, 70.0]
$shotChanges = ShotChanges::fromText("12.5\n00:01:02.500\n70\n");               // the same times

ShotChangeTiming::apply($subtitle, $shotChanges, new ShotChangeOptions(
    frameRate: 24,
    snapWindow: 12,        // frames, default half a second: 12 at 23.976, 24 and 25 fps, 15 at 29.97 fps
    minGapFrames: 2,       // frames between a cue and the next cue or shot change, default 2
    chain: true,           // true (default) closes small gaps, false keeps them
    minDuration: 20,       // frames, default 20
));
ShotChangeTiming::chainGaps($subtitle, new ShotChangeOptions(frameRate: 24));   // only closes small gaps
```

| Rule | Before, at 24 fps | After |
|:--- |:--- |:--- |
| An in-time up to `snapWindow` frames after a shot change moves to the shot change. | shot change 62.500, cue starts 62.708 | starts 62.500 |
| An out-time up to `snapWindow` frames before a shot change ends `minGapFrames` before it. | shot change 70.000, cue ends 69.750 | ends 69.917 |
| **Chaining**: a gap of more than `minGapFrames` and less than `snapWindow` frames closes to `minGapFrames`. The earlier cue ends later. | cue A ends 10.000, cue B starts 10.292 | A ends 10.208 |

- **Frames**: all cue times of the result fall on frames of `frameRate`, rounded to milliseconds. The first step rounds each time to the nearest frame.
- **Blocked moves**: a move does not happen when it makes a cue shorter than `minDuration`, or when it brings the cue closer than `minGapFrames` to the cue before or after it. A move that makes a short cue longer still happens.
- **Order**: `apply()` moves the out-times first, then the in-times, then chains. It handles the cues in start order.
- **Chaining across a cut**: `apply()` does not chain a gap that holds a shot change. `chainGaps()` knows no shot changes and chains every small gap.
- **Input**: `fromFfmpegLog()` reads the `pts_time:` values. `fromText()` reads one time per line, in seconds or as `hh:mm:ss.mmm`, and skips empty lines. Both return the times sorted, without duplicates.

## Translation
`TranslationRunner` sends the cue text to a machine translation engine and returns a translated copy. The `language` metadata of the copy becomes the target language. The original subtitle does not change.

```php
use SubtitleToolbox\Translation\TranslationOptions;
use SubtitleToolbox\Translation\TranslationRunner;

$runner  = new TranslationRunner(new DeepLEngine($apiKey));
$english = $runner->translate($german, 'de', 'en-US');
$english = $runner->translate($german, 'de', 'en-US', new TranslationOptions(
    joinSentences: true,              // send cues of one sentence as one text
    maxCuesPerSentence: 3,            // most cues in one text
    maxCharactersPerRequest: 5000,    // most characters in one engine call
));
$runner->getWarnings();               // list of TranslationWarning with cueIndex and message
```

- **Sentences**: a cue that does not end with `.`, `?`, `!`, the ellipsis U+2026 or a CJK end mark such as U+3002 joins the next cue. Cue 1 `The train to Basel leaves` and cue 2 `from platform 4.` go out as one text. The runner splits the translation back in proportion to the characters of the cues, at a space. In Chinese, Japanese and Thai text it splits between two characters.
- **Lines**: a cue that goes out alone keeps its line breaks when the engine keeps them. Cues that go out as one text come back with one line each. Call `wrapLines()` to break long lines again.
- **Tags**: the runner replaces tags with numbered placeholders, for example `<i>Run!</i>` becomes `<x1>Run!</x1>`, and a word timestamp becomes `<x2/>`. It restores the tags after the translation. A tag that spans two cues of one sentence closes at the end of the first cue and opens again in the next.
- **Dropped placeholder**: when the engine drops, adds or breaks a placeholder, the runner removes all tags of the text and adds a `TranslationWarning` for each cue.
- **Entities**: the engine gets `&lt;`, `&gt;` and `&amp;`. The runner decodes other entities in the answer, such as `&#39;`, and escapes a bare `&`, `<` or `>`.
- **Not sent**: cues with only numbers, punctuation, symbols such as the music note U+266A, or no text keep their text.
- **Requests**: each engine call gets whole texts up to `maxCharactersPerRequest` characters. A longer text goes out alone.
- **Engine errors**: `translate()` throws `InvalidArgumentException` when the engine does not return one string per text. Exceptions of the engine pass through.

Engines are separate Composer packages that implement one method. This engine uses [deeplcom/deepl-php](https://github.com/DeepLcom/deepl-php):

```php
use DeepL\DeepLClient;
use SubtitleToolbox\Translation\TranslationEngine;

final class DeepLEngine implements TranslationEngine
{
    private DeepLClient $client;


    public function __construct(string $authKey)
    {
        $this->client = new DeepLClient($authKey);
    }


    public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
    {
        $results = $this->client->translateText($texts, $sourceLanguage, $targetLanguage, ['tag_handling' => 'xml']);

        return array_map(fn ($result): string => $result->text, $results);
    }
}
```

- **Texts**: each text holds placeholders and the entities `&lt;`, `&gt;` and `&amp;`, so it is valid XML content. Tell the engine to keep tags, for example with `tag_handling` for DeepL.
- **Answer**: return one string per text, in the same order.

## HLS
HLS (HTTP Live Streaming) cuts a subtitle track into short WebVTT files, the segments, and lists them in an `.m3u8` playlist. Each segment has an `X-TIMESTAMP-MAP` header. The header maps a WebVTT cue time to the 90 kHz MPEG-2 timestamp of the video.

```php
use SubtitleToolbox\Hls\HlsSegmentOptions;
use SubtitleToolbox\Hls\HlsWebVttJoiner;
use SubtitleToolbox\Hls\HlsWebVttSegmenter;
use SubtitleToolbox\Hls\TimestampMap;

$hls = HlsWebVttSegmenter::segment($subtitle, new HlsSegmentOptions(
    segmentDuration: 6,                 // seconds
    mpegts: 900000,                     // MPEG-2 timestamp at which subtitle time 0 plays
    local: 0,                           // WebVTT cue time in seconds that maps to mpegts
    fileNamePattern: 'sub%d.vtt',       // %d is the 0-based segment number
    mediaDuration: 20,                  // seconds the playlist covers, null for the end of the last cue
));
foreach ($hls->getSegments() as $name => $vtt) {   // 'sub0.vtt' => "WEBVTT\nX-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:900000\n\n..."
    file_put_contents("out/$name", $vtt);
}
file_put_contents('out/subs.m3u8', $hls->getPlaylist());

$subtitle = HlsWebVttJoiner::join([$vtt0, $vtt1, $vtt2, $vtt3]);    // cue times from the start of the stream
$subtitle = HlsWebVttJoiner::join($segments, streamStartPts: 126000);

$map = TimestampMap::fromHeader('X-TIMESTAMP-MAP=MPEGTS:181083,LOCAL:00:00:00.000');
$map->offset(126000);                   // about 0.612, the seconds to add to a cue time
```

The playlist for a 20 s subtitle with 6 s segments:

```
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:6
#EXT-X-MEDIA-SEQUENCE:0
#EXT-X-PLAYLIST-TYPE:VOD
#EXTINF:6.000,
sub0.vtt
#EXTINF:6.000,
sub1.vtt
#EXTINF:6.000,
sub2.vtt
#EXTINF:2.000,
sub3.vtt
#EXT-X-ENDLIST
```

- **Cues across a boundary**: a cue goes into every segment that it overlaps, with its full start and end time. [RFC 8216 section 3.5](https://datatracker.ietf.org/doc/html/rfc8216#section-3.5) requires this. A cue without an identifier gets its number in the whole subtitle, so it has the same identifier in each segment.
- **Empty segments**: a segment without cues still has the header. Apple's [HLS authoring specification](https://developer.apple.com/documentation/http-live-streaming/hls-authoring-specification-for-apple-devices) item 5.5 requires a subtitle playlist for the whole content. Set `mediaDuration` to the video duration for that.
- **Header**: the segmenter writes `LOCAL` before `MPEGTS`, as in RFC 8216 section 3.5. A cue at subtitle time `t` gets the WebVTT time `t + local`. `TimestampMap::fromHeader()` reads both attribute orders.
- **Playlist**: a VOD media playlist with `#EXT-X-VERSION:3`, because decimal `#EXTINF` durations need version 3 (RFC 8216 section 7). `#EXT-X-TARGETDURATION` is the largest `#EXTINF` duration rounded to the nearest integer (RFC 8216 section 4.3.3.1). Apple recommends 6 s segments in item 7.5.
- **Segment files**: UTF-8 without BOM, LF line endings. The header text, other header lines, `STYLE` and `REGION` blocks of the subtitle go into each segment. An old `X-TIMESTAMP-MAP` line is replaced. Comments are not copied.
- **Joining**: `join()` parses the segments in playlist order, adds each map's `offset()` to its cue times, and keeps one copy of a cue that repeats with the same times and text. Then `removeDuplicateCues()` joins a cue that a segmenter split at a boundary. That call also joins two cues with the same text in the source when one ends at the start of the next.
- **Stream start**: `join()` returns cue times from `streamStartPts`. Without it, the `MPEGTS` value of the first segment is the start. A segment without the header maps cue time 0 to `MPEGTS` 0, as RFC 8216 section 3.5 requires. A cue time that becomes negative becomes 0.
- **Timestamp wrap**: MPEG-2 timestamps have 33 bits and wrap after about 26.5 hours. `offset()` takes the shorter way around the wrap, so a difference above half the range counts as a wrap.

## Forced cues
A **forced cue** shows also when the viewer has turned subtitles off, for example the translation of a sign. Apple and Netflix take a full subtitle file and a separate file with only the forced cues.

```php
$subtitle = Subtitle::parse(file_get_contents('movie.itt'));   // <p itts:forcedDisplay="true">Sector 7 ahead</p>
$subtitle->getCues()[3]->isForced();                            // true
$subtitle->getCues()[4]->setForced(true);
$forced = $subtitle->forcedOnly();                              // a new Subtitle with copies of the forced cues
file_put_contents('movie.forced.itt', $forced->format(IttFormatter::class));
```

| Format | Read | Write |
|:--- |:--- |:--- |
| TTML, IMSC, DFXP | `itts:forcedDisplay="true"` on `p`, `span`, `div`, `body`, the region or a referenced style | the same attribute on `p` |
| iTT | as TTML | the same attribute on `p` |
| PGS, VobSub | the forced flag of the object or unit | no writer |
| JSON | `forced` | `forced` |
| other formats | no flag | the flag is lost |

- **Default**: a cue is not forced. A cue from a format without the flag is not forced.
- **TTML spans**: one forced `span` makes the whole cue forced. The formatter then writes the flag on the `p`, so the whole paragraph becomes forced.
- **TTML output**: the formatter writes `itts:forcedDisplay` on the `p` only when the stored `p`, `div` or region attributes give another value. It declares the `itts` namespace on `<tt>` when the input file did not.
- **Image cues**: `CueImage::toCue()` sets the cue flag from the `forced` field of the image. OCR keeps the flag.
- **JSON**: `toArray()` and `JsonFormatter` write `"forced": true` after `alignment`, only for a forced cue. The `version` stays 1. `fromArray()` accepts `true`, `false` or no field.
- **`forcedOnly()`**: works as `slice()`. The copy keeps the metadata, the format data and the comments before the forced cues. The original stays unchanged.
- **Compare**: `SubtitleDiff` reports a cue whose flag changed as `text changed`. `toText()` writes `forced` after the times of a forced cue.

## Fixing common errors
OCR of PGS and VobSub cues reads `It's` as `lt's`. Files from the web have spaces before `?` and tags that never close. `CommonErrorFixer` fixes such errors in one call and lists each change for review.

```php
use SubtitleToolbox\Fixing\CommonErrorFixer;
use SubtitleToolbox\Fixing\CommonErrorOptions;
use SubtitleToolbox\Fixing\OcrReplaceList;

$fixes = CommonErrorFixer::fix($subtitle, new CommonErrorOptions(language: 'en'));
$fixes[0]->cueIndex;   // 14
$fixes[0]->rule;       // 'ocrLowercaseL'
$fixes[0]->before;     // "lt's late."
$fixes[0]->after;      // "It's late."

CommonErrorFixer::fix($subtitle, new CommonErrorOptions(
    language: 'fr',
    dialogueDash: '-',                                       // '- ' (default), '-', or an en or em dash with or without a space
    unicodeEllipsis: true,                                   // writes U+2026 for every ellipsis
    replaceList: OcrReplaceList::fromSubtitleEditXml(file_get_contents('fra_OCRFixReplaceList_User.xml')),
    dryRun: true,                                            // lists the fixes and changes nothing
));
```

| Option | Before | After |
|:--- |:--- |:--- |
| `doubleSpaces` | `Hi <i> there</i>` | `Hi <i>there</i>` |
| `spaceBeforePunctuation` | `Really ?` | `Really?`. French keeps the space before `?`, `!`, `:` and `;` |
| `missingSpaceAfterPunctuation` | `Stop.Now`, `Hi!How` | `Stop. Now`, `Hi! How`. Not in `1.5`, `www.example.com`, `e.g.` or `U.S.Army` |
| `unbalancedTags` | `<i>Hello` | `<i>Hello</i>` |
| `emptyTags` | `Hi <i></i>there` | `Hi there` |
| `dialogueDashes` | `-Hi.` and `-Hello.` | `- Hi.` and `- Hello.`, or the style of `dialogueDash` |
| `ellipsis` | `. . .` or `....` | `...`, or U+2026 with `unicodeEllipsis` |
| `ocrLowercaseL` | `lt's`, `l'm`, `l'll`, `lT lS` | `It's`, `I'm`, `I'll`, `IT IS` |
| `ocrPipe` | `\|t was`, `wi\|\|` | `It was`, `will` |
| `ocrZeroInWords` | `D0N'T`, `n0rth` | `DON'T`, `north`. Not in `007` or `2.0` |
| `replaceList` | the words of an `OcrReplaceList` | the replacement |

- **Defaults**: every fix is on, except `replaceList` and `unicodeEllipsis`. The fixes run in the order of `CommonErrorFixer::RULES`. The result holds one `AppliedFix` for each rule that changed a cue.
- **Visible text only**: the fixes see the text between tags, with `&lt;`, `&gt;` and `&amp;` decoded, as `replaceText()` does. Only `unbalancedTags` and `emptyTags` change tags. A tag spans the lines of its cue, so `unbalancedTags` closes a tag at the end of the last line. It removes a closing tag without an opening tag.
- **Double spaces**: a cue line never holds two spaces in a row, because `SubtitleCue` joins them. `doubleSpaces` removes the space after a tag when a space comes before it, and joins non-breaking spaces.
- **Language**: `language` takes a code such as `en`, `de-AT` or `fra`. Null takes the `language` metadata of the subtitle. English, German, French and Spanish have their own rules for I and l. Other languages get only the rules that apply to all languages, for example `lT` to `IT`.
- **I and l**: OCR reads a capital I as l when the font draws both the same. `ocrLowercaseL` changes an `l` at the start of a word before a consonant: `lch` to `Ich`, `lsabel` to `Isabel`. French also changes `ll` to `Il`, and keeps `l'hôtel`. Spanish keeps `llega`. English also changes `l`, `l'm`, `l'll`, `l've` and `l'd`. `5 lbs` and `2 l` stay.
- **Image cues**: run the fixes after `recognizeText()`. Cues without text lines stay unchanged.
- **Empty cues**: a cue that the replace list empties goes. `cueIndex` is the index before the removal.
- **Limits**: a fix sees one text run, so it does not find `l<i>t's</i>`. A 0 that stands for another letter, such as `B0ro` for `Büro`, becomes `o`.

`OcrReplaceList::fromSubtitleEditXml()` reads an OCR replace list of [Subtitle Edit](https://github.com/SubtitleEdit/subtitleedit), such as `eng_OCRFixReplaceList_User.xml`. The package ships no list. Pass the arrays to `new OcrReplaceList(wholeWords: ['Teh' => 'The'])` to build a list in code.

| Section | Replaces |
|:--- |:--- |
| `WholeWords` | a word between spaces, also with the punctuation around it, such as `"Teh,` |
| `PartialWordsAlways` | a part of any word, before the `WholeWords` lookup |
| `WholeLines` | the whole visible text of a line |
| `BeginLines` | the start of a line, after a dialogue dash or a quote, and the start of a sentence after `. `, `! ` or `? ` |
| `EndLines` | the end of the cue. It adds no period when the next cue starts with a lower case letter within 0.6 s |
| `PartialLines` | a text that starts and ends at a space, a punctuation mark or the line edge |
| `PartialLinesAlways` | any part of a line |
| `RegularExpressions` | a .NET pattern, run with PCRE on each text run. `^` and `$` match at the tags around the run |

- **Rules**: the sections work as in `OcrFixReplaceList2.cs` of Subtitle Edit at commit [`e1b8546`](https://github.com/SubtitleEdit/subtitleedit/blob/e1b854665b40bf6e04271c2ec64084947060e632/src/libuilogic/Ocr/FixEngine/OcrFixReplaceList2.cs), MIT license.
- **Skipped**: `PartialWords` and `RegularExpressionsIfSpelledCorrectly` need a spell checker. `Removed...` sections change the list that Subtitle Edit ships. A regular expression that PCRE rejects, or a replacement with a named group such as `${name}`, is skipped as Subtitle Edit skips invalid ones.
- **Errors**: XML that does not parse throws `ParsingException` with the line. An invalid PCRE pattern in the constructor throws `InvalidArgumentException`.

## Releases
Every merge to `master` publishes a release to Packagist. The PR label sets the version bump.
CI fails a PR that does not carry exactly one of these labels:

| Label | Effect |
|:--- |:--- |
| `major` | 1.2.3 becomes 2.0.0 |
| `minor` | 1.2.3 becomes 1.3.0 |
| `patch` | 1.2.3 becomes 1.2.4 |
| `skip-release` | no release |

Renovate labels its dependency PRs `patch`.
