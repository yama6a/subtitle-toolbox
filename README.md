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

## Restrictions
This project currently focuses on adding basic support for additional formats, rather than more sophisticated functionality, such as comments, styling, and cue positioning. 

## Supported formats
| Format | Reads | Outputs | Additional Info
|:--- |:--- |:--- |:--- |
| LyRiCs (.lrc)   | No support for ID tags | No support for ID tags | Strips all xml tags, including word-timing of enhanced LRC files
| SubRip (.srt)   | Full Support | Full Support  | Formatter strips all xml tags except: \<b>\<i>\<u>\<font>
| MpSub (.mpsub)  | n/a | Only supports FORMAT=TIME, No support for metadata | Formatter strips all xml tags  
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
