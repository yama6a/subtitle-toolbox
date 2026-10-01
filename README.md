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

## Restrictions
This project currently focuses on adding basic support for additional formats, rather than more sophisticated functionality, such as comments, styling, and cue positioning. 

## Supported formats
| Format | Reads | Outputs | Additional Info
|:--- |:--- |:--- |:--- |
| LyRiCs (.lrc)   | No support for ID tags | No support for ID tags | Strips all xml tags, including word-timing of enhanced LRC files
| SubRip (.srt)   | Full Support | Full Support  | Formatter strips all xml tags except: \<b>\<i>\<u>\<font>
| MpSub (.mpsub)  | n/a | Only supports FORMAT=TIME, No support for metadata | Formatter strips all xml tags  
| SBV (.sbv)      | Accepts any number of hour digits | Writes one hour digit below 10 hours, no UTF-8 BOM | Formatter strips all xml tags and decodes HTML entities
| WebVTT (.vtt)   | No Support for comments, styling or positioning| No Support for comments, styling or positioning | Formatter strips all xml tags except: \<b>\<u>\<i>\<v>\<lang>\<c>\<ruby>\<rt>

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
