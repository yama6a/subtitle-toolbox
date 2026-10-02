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
The parsers and formatters do not read or write these fields yet.

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
The parsers and formatters do not read or write these fields yet.

```php
$cue->setAlignment(8);                                  // top center
$cue->setFormatData('ass', ['style' => 'Sign']);
$subtitle->getFormatData('ass');                        // [] when not set
```

- **Alignment**: a number from 1 to 9 in numeric keypad layout. 1 is bottom left, 2 is bottom center, 8 is top center. `null` means the format default, bottom center.
- **Format data**: styling outside the core markup and the alignment. Only the formatter of the same format reads it. The key is the lowercase file extension of the format, for example `ass` or `vtt`.

## Restrictions
This project currently focuses on adding basic support for additional formats, rather than more sophisticated functionality, such as comments, styling, and cue positioning. 

## Supported formats
| Format | Reads | Outputs | Additional Info
|:--- |:--- |:--- |:--- |
| LyRiCs (.lrc)   | No support for ID tags | No support for ID tags | Strips all xml tags, including word-timing of enhanced LRC files
| SubRip (.srt)   | Full Support | Full Support  | Formatter strips all xml tags except: \<b>\<i>\<u>\<s>\<font>
| MicroDVD (.sub) | Frame rate from the parser constructor or a `{1}{1}<fps>` first line | Needs `OPTION_FRAME_RATE` | Converts `{y:b}`, `{y:i}`, `{y:u}`, `{y:s}` and `{c:$BBGGRR}` to core markup. Keeps other control codes in the `sub` format data
| MpSub (.mpsub)  | n/a | Only supports FORMAT=TIME, No support for metadata | Formatter strips all xml tags  
| SBV (.sbv)      | Accepts any number of hour digits | Writes one hour digit below 10 hours, no UTF-8 BOM | Formatter strips all xml tags and decodes HTML entities
| WebVTT (.vtt)   | No Support for comments, styling or positioning| No Support for comments, styling or positioning | Formatter strips all xml tags except: \<b>\<u>\<i>\<v>\<lang>\<c>\<ruby>\<rt>

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
