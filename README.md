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
| iTunes Timed Text (.itt) | The TTML parser with the SMPTE timing parameters in the `itt` format data | SMPTE times `hh:mm:ss:ff`, one `div`, a `top` and a `bottom` region. Needs a frame rate | Writes bold, italic, underline and text colour as `tts:` attributes on `<span>`. Strips all other tags
| LyRiCs (.lrc)   | ID tags, `[offset:]`, several timestamps per line, enhanced LRC word timing | ID tags, `[#:]` comments, word timing as `<mm:ss.xx>` | Formatter strips all other xml tags and writes times in centiseconds. Text with `<`, `>` and `&` round-trips
| MicroDVD (.sub) | Frame rate from the parser constructor or a `{1}{1}<fps>` first line | Needs `OPTION_FRAME_RATE` | Converts `{y:b}`, `{y:i}`, `{y:u}`, `{y:s}` and `{c:$BBGGRR}` to core markup. Keeps other control codes in the `sub` format data
| MpSub (.mpsub)  | FORMAT=TIME and FORMAT=<fps>, header lines | FORMAT=TIME by default, FORMAT=<fps> as an option, header lines | Formatter strips all xml tags. Text with `<`, `>` and `&` round-trips
| SAMI (.smi)     | One language class, `<TITLE>`, the `<STYLE>` block and `<SAMIParam>` | Writes them back, and a `&nbsp;` SYNC after each cue that has a gap before the next cue | Converts `<b>`, `<i>`, `<u>`, `<s>`, `<strike>` and `<font color>` to core markup. Formatter strips all xml tags except: \<b>\<i>\<u>\<s>\<font>
| SBV (.sbv)      | Accepts any number of hour digits | Writes one hour digit below 10 hours, no UTF-8 BOM | Formatter strips all xml tags and decodes HTML entities. Text with `<`, `>` and `&` round-trips
| SSA (.ssa)      | SubStation Alpha v4.00 with `[V4 Styles]` and `Marked=` columns | Writes SSA back when the parsed file was SSA, legacy `\a` alignment tags | Same parser and formatter as ASS
| SubRip (.srt)   | Reads coordinates, alignment tags and lenient timestamps | Writes standard timestamps, coordinates and alignment tags | Formatter strips all xml tags except: \<b>\<i>\<u>\<s>\<font>
| TTML (.ttml, .dfxp, .xml) | TTML 1, TTML 2, IMSC and the DFXP namespace. All time expressions, `body` and `div` offsets | Media clock times, `<head>` and attributes of the input file | Converts `tts:fontWeight`, `tts:fontStyle`, `tts:textDecoration`, `tts:color` and `ttm:agent` to core markup and back
| VobSub (.idx and .sub) | DVD bitmaps as image cues. The `size`, `palette`, `custom colors`, `id`, `delay` and `timestamp` lines of the `.idx` | Not supported | See [VobSub](#vobsub)
| WebVTT (.vtt)   | Header, comments, cue identifiers, styles, regions and cue settings | Writes them back, numbers cues without identifier, always writes hours | Formatter strips all xml tags except: \<b>\<u>\<i>\<v>\<lang>\<c>\<ruby>\<rt> and inline timestamps

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
| 9 | `LyricsParser` | `[ti:Title]` or `[00:12.00]`, and at least one timestamp line |

- **Order**: a format with a more specific signature comes first. A WebVTT file without its `WEBVTT` line looks like SubRip, so it detects as SubRip.
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
- **Engines**: this package ships no OCR engine, so it has no native dependencies. An engine is a separate Composer package that implements `OcrEngine`. `TesseractEngine` above is such a package.
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
- **Words**: the text without tags, split at whitespace. A dialogue dash counts as a word. `getMostUsedWords()` removes punctuation at the start and end of each word and compares in lower case.
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
