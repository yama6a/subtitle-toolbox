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
| LyRiCs (.lrc)   | ID tags, `[offset:]`, several timestamps per line, enhanced LRC word timing | ID tags, `[#:]` comments, word timing as `<mm:ss.xx>` | Formatter strips all other xml tags and writes times in centiseconds
| MicroDVD (.sub) | Frame rate from the parser constructor or a `{1}{1}<fps>` first line | Needs `OPTION_FRAME_RATE` | Converts `{y:b}`, `{y:i}`, `{y:u}`, `{y:s}` and `{c:$BBGGRR}` to core markup. Keeps other control codes in the `sub` format data
| MpSub (.mpsub)  | FORMAT=TIME and FORMAT=<fps>, header lines | FORMAT=TIME by default, FORMAT=<fps> as an option, header lines | Formatter strips all xml tags
| SAMI (.smi)     | One language class, `<TITLE>`, the `<STYLE>` block and `<SAMIParam>` | Writes them back, and a `&nbsp;` SYNC after each cue that has a gap before the next cue | Converts `<b>`, `<i>`, `<u>`, `<s>`, `<strike>` and `<font color>` to core markup. Formatter strips all xml tags except: \<b>\<i>\<u>\<s>\<font>
| SBV (.sbv)      | Accepts any number of hour digits | Writes one hour digit below 10 hours, no UTF-8 BOM | Formatter strips all xml tags and decodes HTML entities
| SSA (.ssa)      | SubStation Alpha v4.00 with `[V4 Styles]` and `Marked=` columns | Writes SSA back when the parsed file was SSA, legacy `\a` alignment tags | Same parser and formatter as ASS
| SubRip (.srt)   | Reads coordinates, alignment tags and lenient timestamps | Writes standard timestamps, coordinates and alignment tags | Formatter strips all xml tags except: \<b>\<i>\<u>\<s>\<font>
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
- **Encoding**: the parser reads UTF-8 only. It throws `ParsingException` for other encodings, for example EUC-KR or CP949.

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
