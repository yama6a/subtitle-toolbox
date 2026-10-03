# Command line tool

`subtitle-toolbox` converts, retimes, checks and fixes subtitle files. Composer installs it as `vendor/bin/subtitle-toolbox`. It needs no package beyond the library.

```sh
vendor/bin/subtitle-toolbox convert movie.srt movie.vtt
vendor/bin/subtitle-toolbox convert season1/ --to vtt --output-dir out/ --keep-going
vendor/bin/subtitle-toolbox convert movie.sub movie.srt --fps 23.976
vendor/bin/subtitle-toolbox shift movie.srt --by -2.5 --output movie.fixed.srt
vendor/bin/subtitle-toolbox fps *.srt --from 25 --to 23.976 --in-place
vendor/bin/subtitle-toolbox fix movie.srt --overlaps --min-gap 0.083 --wrap 42 --output movie.fixed.srt
vendor/bin/subtitle-toolbox validate movie.srt --preset netflix-en --json
curl -s https://example.com/movie.srt | vendor/bin/subtitle-toolbox convert - --to vtt > movie.vtt
```

## Install without Composer
Every release also ships the tool as a PHAR file and as a container image.

| Form | Needs | Example |
|:--- |:--- |:--- |
| PHAR on the [GitHub release](https://github.com/yama6a/subtitle-toolbox/releases) | PHP 8.2 or later with `ext-dom`, `ext-iconv` and `ext-zlib` | `php subtitle-toolbox.phar convert in.srt out.vtt` |
| Image `ghcr.io/yama6a/subtitle-toolbox` | Docker or another container runtime | `docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/work" ghcr.io/yama6a/subtitle-toolbox:1.65.0 convert in.srt out.vtt` |

```sh
curl -fsSLO https://github.com/yama6a/subtitle-toolbox/releases/latest/download/subtitle-toolbox.phar
php subtitle-toolbox.phar --version
```

- **Version**: the PHAR file, the image tag, the Git tag and the Packagist version are the same string, for example `1.65.0`. The image also has the tags `1.65`, `1` and `latest`.
- **Image**: the tool runs in `/work`, so mount your files there. `--user` makes the tool write files that you own. Without it, the tool runs as `www-data` and cannot write to most mounted folders.
- **Platforms**: the image is for `linux/amd64` and `linux/arm64`.
- **OCR**: `convert --ocr` works in both forms with no extra steps, because both include php-glyph-ocr.
- **Memory**: the image sets `memory_limit` to 512 MB. The PHAR raises a `memory_limit` of 128 MB to 512 MB when you pass `--ocr`. It keeps any other value, for example from `php -d memory_limit=1G`.

## Commands
| Command | Does |
|:--- |:--- |
| `convert` | writes each input in the format of `--to` or of the output file extension |
| `shift` | moves all cues earlier or later by `--by` seconds, or only the cues from `--after` seconds |
| `scale` | multiplies all cue times by `--factor` |
| `fps` | retimes a subtitle `--from` one frame rate `--to` another. `sync-fps` is another name for it |
| `fix` | fixes text errors, overlapping cues, short cues and long lines, see [Fix](#fix) |
| `strip-sdh` | removes hearing-impaired annotations, as [`removeHearingImpaired()`](text.md#hearing-impaired-annotations) does |
| `info` | prints the format, the cue count and statistics, as text or with `--json`. Lists the tracks of an MKV or WebM file |
| `validate` | prints each broken rule, as text or with `--json`, see [Validate](#validate) |
| `sync` | retimes a subtitle to a reference subtitle or to the speech, see [Sync](#sync) |
| `diff` | lists the added, removed and changed cues of two files, see [Diff](#diff) |
| `dual` | merges two languages into one file, see [Dual](#dual) |
| `hls` | cuts a subtitle into WebVTT segments and writes an HLS playlist, see [HLS](#hls) |
| `formats` | lists the format names and extensions for `--from` and `--to` |

- **Help**: `subtitle-toolbox help convert` or `subtitle-toolbox convert --help` lists all options of a command.
- **Version**: `subtitle-toolbox --version` prints the installed release, for example `1.65.0`, or `dev` in a Git checkout.
- **Exit code**: 0 when all files succeed, 1 when a file fails, breaks a validation rule or differs in `diff`, 2 for invalid arguments.

## Input and output
- **Inputs**: a file, a directory, a glob such as `"season1/*.srt"`, or `-` for standard input. A directory gives its files with a known extension.
- **Input format**: `--from`, else format detection on the content, else the file extension.
- **Output**: `--output` for one file, `--output-dir`, or `--in-place`. `--output -` writes standard output. Without these, `convert` writes next to the input with the new extension, and the other commands write standard output.
- **Overwrite**: the tool never overwrites a file without `--force` or `--in-place`.
- **Batch**: the tool prints one line per file and a summary. It stops at the first failed file, unless you pass `--keep-going`.
- **Encoding**: `--encoding` names the encoding of the input, for example `Windows-1252`. See [encodings.md](encodings.md).
- **Output bytes**: `--line-ending lf|crlf`, `--bom` and `--no-bom`.
- **Broken files**: `--lenient` skips or repairs broken cues and prints a warning for each, see [lenient-parsing.md](lenient-parsing.md).
- **Frame rate**: `--fps` gives the frame rate for a MicroDVD file without a `{1}{1}<fps>` first line. MicroDVD and iTT output also use it. Without it, MicroDVD output takes the frame rate of a MicroDVD input, and iTT output the frame rate of an iTT input.
- **Word timestamps**: `--word-timestamps` keeps the word times of the speech-to-text JSON formats, YouTube timed text and Podcasting 2.0 transcripts. `fix --resegment`, `convert --karaoke` and `convert --karaoke-tag` turn it on.
- **MKV and WebM**: `--track` picks a subtitle track, see [MKV and WebM](#mkv-and-webm).
- **Image cues**: `--skip-image-cues` leaves out image cues without text in place of failing.

## Formats and file extensions
Run `subtitle-toolbox formats` for the list. When two formats share an extension, the first one in the list owns it.

- **Output extension**: when the input format also uses the extension of the output file, the output keeps the input format. So an MPL2 `film.txt` converts to MPL2 in `out.txt`. Otherwise the owner of the extension decides: an SRT input and `out.txt` give plain text. A Whisper JSON input and `out.json` give the library JSON, because the tool cannot write Whisper JSON.
- **`.sub`**: MicroDVD. Pass `--from subviewer` for SubViewer. A directory skips a `.sub` file that has an `.idx` file next to it.
- **VobSub**: pass the `.idx` file. The tool reads the `.sub` file next to it. Standard input does not work.
- **`.json` and `.txt` input**: format detection finds the speech-to-text JSON formats, podcast transcripts, Podcasting 2.0 chapters, MPL2, TMPlayer and OGM chapters by their content.
- **Other output formats**: pass `--to`, for example `--to mpl2`, `--to podcast-transcript` or `--to ytchapter`.
- **CSV and TSV**: TSV output has tabs between the cells. CSV output from a TSV input has commas. Other CSV output keeps the delimiter of the input table.

## MKV and WebM
Every command reads a subtitle track of an MKV or WebM file with [`MatroskaReader`](mkv.md). `--track` takes the track number that `info` lists.

```sh
vendor/bin/subtitle-toolbox info movie.mkv
vendor/bin/subtitle-toolbox convert movie.mkv movie.srt --track 3
vendor/bin/subtitle-toolbox convert movie.mkv movie.srt --track 5 --ocr
```

```
movie.mkv
  Format: matroska
  Track 3: S_TEXT/UTF8, de, "Deutsch (Forced)", forced
  Track 4: S_TEXT/ASS, eng, "English", default
  Track 5: S_HDMV/PGS, eng
```

- **Detection**: the tool knows an MKV or WebM file by its first 4 bytes, not by its extension. Standard input works too.
- **Track**: a file with one subtitle track needs no `--track`. For a file with more, the tool fails and lists the tracks.
- **Format**: an `S_TEXT/UTF8` track is SubRip, ASS and SSA tracks are ASS, `S_TEXT/WEBVTT` is WebVTT and `S_HDMV/PGS` is PGS. Without `--to`, the output keeps this format. `convert movie.mkv --to srt` writes `movie.srt`.
- **Info**: without `--track`, `info` lists the tracks. In the JSON, each track has `number`, `codecId`, `language`, `name`, `default` and `forced`. With `--track`, `info` prints the statistics of the track.
- **Directories**: a directory argument skips MKV and WebM files. Pass them by name or with a glob.
- **Errors**: `S_VOBSUB` tracks, bzlib and LZO compression and encryption fail, see [mkv.md](mkv.md).

## Convert
| Option | Effect |
|:--- |:--- |
| `--strip-tags` | removes all formatting tags, such as `<i>` and `<font>` |
| `--speakers MODE` | `prefix`, `dashes`, `colours` or `from-prefix`. Calls `toPrefix()`, `toDialogueDashes()`, `toColours()` or `fromPrefix()` with the default arguments, see [Speakers](text.md#speakers) |
| `--replace FROM=TO` | [`replaceText()`](text.md#transforms) on the text between tags. Repeatable. The first `=` ends FROM |
| `--regex` | reads each FROM as a regular expression with delimiters, for example `--replace '/\.{4,}/=...'` |
| `--ignore-case` | matches FROM in any case |
| `--case MODE` | `upper`, `lower` or `sentence`, with `changeCase()` |
| `--case-language CODE` | `tr` or `az` for the Turkish rules of `i` and `ı` |
| `--mask-words FILE` | masks the words of a word file, as [`ProfanityFilter::apply()`](text.md#profanity-filter) does |
| `--mask STYLE` | `stars` (default), `first-letter`, `remove`, or `none`. `none` keeps the text and only finds the times for `--mute-edl` and `--mute-filter` |
| `--mute-edl FILE` | writes the times of the matches to an EDL file with [`MuteRange::toEdl()`](text.md#profanity-filter), for Kodi and MPlayer |
| `--mute-filter FILE` | writes the FFmpeg volume filter of `MuteRange::toFfmpegVolumeFilter()` |
| `--mute-padding SECONDS` | widens each time range on both sides, default 0 |
| `--karaoke` | writes one cue per word with the active word styled, with [`WordHighlight::expand()`](text.md#word-highlight-and-karaoke) |
| `--karaoke-style TAG` | `b`, `i`, `u` (default), `s` or `'font color="#ffff00"'` |
| `--karaoke-mode MODE` | `word` (default) styles the active word, `cumulative` all words up to it |
| `--karaoke-words N` | shows only N words around the active word |
| `--karaoke-tag TAG` | `k` (default), `kf` or `ko`, the ASS tag for word timestamps, see [formats.md](formats.md#ass-and-ssa). Needs ASS output |
| `--forced-only` | keeps only the [forced cues](subtitle.md#forced-cues) |
| `--ocr` | reads the text of image cues, see [OCR](#ocr) |
| `--ocr-database FILE` | the `.nocr` glyph database for `--ocr` |

```sh
vendor/bin/subtitle-toolbox convert song.json song.srt --karaoke --karaoke-words 5
vendor/bin/subtitle-toolbox convert song.json song.ass --karaoke-tag kf
vendor/bin/subtitle-toolbox convert movie.srt clean.srt --mask-words words.txt --mute-filter mute.txt --mute-padding 0.1
ffmpeg -i movie.mp4 -af "$(cat mute.txt)" -c:v copy clean.mp4
```

- **Order**: `convert` keeps the forced cues and runs OCR first. Then it runs `--speakers`, `--replace`, `--case`, `--mask-words`, `--strip-tags` and `--karaoke`.
- **Mute files**: they need `--mask-words` and one input file. Without `--force`, the tool does not overwrite them.
- **No match**: the filter file is empty. Then leave out `-af`.

## Fix
Pass at least one fix. The fixes run in this order: `--common-errors`, `--resegment`, `--unwrap`, `--merge-short`, `--split-long`, `--wrap`, `--merge-duplicates`, `--overlaps`, `--min-duration`.

| Option | Calls |
|:--- |:--- |
| `--common-errors` | [`CommonErrorFixer::fix()`](text.md#fixing-common-errors) with all default fixes. `--language` sets the language rules, default the `language` metadata. `--replace-list FILE` adds a Subtitle Edit OCR replace list. `--list-fixes` prints each change to standard error |
| `--resegment` | `resegmentByWords()`. `--max-cpl`, `--max-lines` and `--max-word-gap` set `maxCharactersPerLine`, `maxLines` and `maxWordGap`, default 0.6 s |
| `--overlaps` | `fixOverlaps()` with `--min-gap` seconds, default 0 |
| `--min-duration SECONDS` | `extendShortCues()` with `--min-gap` |
| `--wrap CHARS` | `wrapLines()` with `--max-lines`, default 2 |
| `--unwrap` | `unwrapLines()` |
| `--merge-duplicates` | `removeDuplicateCues()` |
| `--merge-short` | `mergeShortCues()` with the default options. `--max-cpl` and `--max-lines` set `maxCharactersPerLine` and `maxLines` |
| `--split-long` | `splitLongCues()` with the default options. `--max-cpl` and `--max-lines` set `maxCharactersPerLine` and `maxLines` |

[editing.md](editing.md) describes each method.

```sh
vendor/bin/subtitle-toolbox convert movie.sup movie.ocr.srt --ocr
vendor/bin/subtitle-toolbox fix movie.ocr.srt --common-errors --language en --list-fixes -o movie.srt
vendor/bin/subtitle-toolbox fix lecture.json --resegment -o lecture.srt
```

`--list-fixes` prints one line per change, for example `movie.ocr.srt: cue 15: ocrLowercaseL: "lt's late." -> "It's late."`.

## Strip SDH
`strip-sdh` removes everything that [`removeHearingImpaired()`](text.md#hearing-impaired-annotations) removes by default.

| Option | Effect |
|:--- |:--- |
| `--keep-square-brackets`, `--keep-parentheses`, `--keep-speaker-labels`, `--keep-music-lines` | turns off one rule |
| `--any-case-labels` | also removes speaker labels that are not upper case, such as `Baker:` |
| `--lyrics` | removes text between two music symbols |
| `--brackets PAIR` | also removes text between this pair, for example `"{}"` or `"**"`. Repeatable |

## Validate
`--preset` takes `netflix-en` or `bbc`, see [validation.md](validation.md#presets). A rule option overrides the value of the preset. `--fps` sets the frame rate for the 2-frame gap of `netflix-en`, default 23.976.

| Option | Rule |
|:--- |:--- |
| `--max-cps`, `--max-cpl`, `--max-lines` | `maxCharactersPerSecond`, `maxCharactersPerLine`, `maxLinesPerCue` |
| `--min-duration`, `--max-duration`, `--min-gap` | `minDuration`, `maxDuration`, `minGap` |
| `--max-wpm`, `--min-seconds-per-word` | `maxWordsPerMinute`, `minSecondsPerWord` |
| `--max-speakers`, `--dialogue-dash STYLE`, `--allowed-characters CHARS` | `maxSpeakersPerCue`, `dialogueDashStyle`, `allowedCharacters` |
| `--no-overlap`, `--no-empty-cues`, `--no-double-spaces` | `noOverlap`, `noEmptyCues`, `noDoubleSpaces` |
| `--no-leading-or-trailing-spaces`, `--no-unbalanced-tags`, `--no-all-caps-lines` | `noLeadingOrTrailingSpaces`, `noUnbalancedTags`, `noAllCapsLines` |

```sh
vendor/bin/subtitle-toolbox validate movie.srt --preset bbc --no-unbalanced-tags --dialogue-dash '- '
```

## Sync
`sync` finds the scale and the offset with [`ReferenceSync`](sync.md), applies them and prints them to standard error.

```sh
vendor/bin/subtitle-toolbox sync movie.de.srt --reference movie.en.srt -o movie.de.synced.srt
```

```
movie.de.srt: scale 1.04271, offset -2.3 s, score 0.89
```

| Option | Sets |
|:--- |:--- |
| `--reference FILE` | the subtitle in sync with the video, in any format that the tool reads. A Whisper JSON transcript of the audio also works |
| `--min-offset SECONDS`, `--max-offset SECONDS` | `minOffset` and `maxOffset`, default -60 and 60 |
| `--no-scale` | `searchScale: false` |
| `--max-splits N`, `--split-penalty SCORE` | `maxSplits`, default 0, and `splitPenalty`, default 0.1 |

- **Score**: below 0.5, the tool also prints that the files likely do not match. The exit code stays 0.
- **Splits**: for each part, the tool prints a line such as `movie.de.srt: from 414.32 s: offset 147.7 s`.
- **Reference**: the tool detects the format of the reference. `--from` and `--track` apply only to the input.

## Diff
`diff` compares an old and a new file with [`SubtitleDiff`](compare.md) and prints `toText()`. The files can have different formats. The exit code is 1 when they differ, as with `diff`. Equal files give no output.

```sh
vendor/bin/subtitle-toolbox diff episode1_v1.srt episode1_v2.srt --ignore-formatting
```

| Option | Sets |
|:--- |:--- |
| `--time-tolerance SECONDS` | `timeTolerance`, default 0.001 |
| `--ignore-formatting`, `--ignore-whitespace`, `--text-only` | `ignoreFormatting`, `ignoreWhitespace`, `textOnly` |
| `--json` | one object with `old`, `new`, `equal` and `differences`. A difference has `kind`, `oldIndex`, `newIndex`, `old` and `new`. A cue has `start`, `end`, `lines` and `forced` |

## Dual
`dual` merges a primary and a secondary subtitle with [`DualSubtitle::merge()`](editing.md#dual-subtitles). The output has the format of the primary file, unless `--to` or the `--output` extension sets another one.

```sh
vendor/bin/subtitle-toolbox dual movie.en.srt movie.de.srt --secondary-style i -o movie.en-de.srt
vendor/bin/subtitle-toolbox dual movie.en.srt movie.de.srt --mode top-bottom -o movie.en-de.ass
```

| Option | Sets |
|:--- |:--- |
| `--mode MODE` | `stack` (default) or `top-bottom` |
| `--secondary-style TAG` | `secondaryStyle`, for example `i` or `'font color="#ffff00"'` |
| `--secondary-alignment 1-9` | `secondaryAlignment` for `top-bottom`, default 8 |
| `--snap-tolerance SECONDS` | `snapTolerance` for `top-bottom`, default 0.25 |

## HLS
`hls` cuts one subtitle into WebVTT segments with [`HlsWebVttSegmenter`](hls.md) and writes them with the playlist into `--output-dir`.

```sh
vendor/bin/subtitle-toolbox hls movie.srt --output-dir hls/ --segment 6 --media-duration 5400
```

This writes `hls/sub0.vtt` to `hls/sub899.vtt` and `hls/subs.m3u8`.

| Option | Default | Sets |
|:--- |:--- |:--- |
| `--output-dir DIR` | required | the directory of the segments and the playlist |
| `--segment SECONDS` | 6 | `segmentDuration` |
| `--playlist NAME` | `subs.m3u8` | the file name of the playlist |
| `--pattern PATTERN` | `sub%d.vtt` | `fileNamePattern`. `%d` is the segment number from 0 |
| `--mpegts TICKS` | 900000 | `mpegts`, the 90 kHz MPEG-2 timestamp at which subtitle time 0 plays |
| `--local SECONDS` | 0 | `local`, the WebVTT cue time that maps to `--mpegts` |
| `--media-duration SECONDS` | the end of the last cue | `mediaDuration`. Set it to the video duration, so the playlist covers the whole video |

- **Overwrite**: `hls` fails before it writes a file when a segment or the playlist exists. Pass `--force` to overwrite.

## OCR
`convert --ocr` reads the image cues of PGS and VobSub files with [`GlyphOcrEngine`](ocr.md#built-in-ocr) before it writes the output. With Composer, it needs the package php-glyph-ocr.

```sh
vendor/bin/subtitle-toolbox convert movie.sup movie.srt --ocr
vendor/bin/subtitle-toolbox convert movie.idx movie.srt --ocr --ocr-database my-font.nocr
```

- **Database**: `--ocr-database` loads a `.nocr` file in place of the Latin database. See [Training a database](ocr.md#training-a-database).
- **Missing package**: without php-glyph-ocr, `--ocr` stops with exit code 2 and prints the `composer require` command.
- **Progress**: the tool prints `movie.sup: OCR 100/1500` to standard error after every 100 image cues and after the last one.
- **Memory**: a 1,500-cue PGS file needs up to 139 MB, above the default `memory_limit` of 128 MB. Run `php -d memory_limit=512M vendor/bin/subtitle-toolbox convert movie.sup movie.srt --ocr` for long files.
- **Info**: `info` prints `Image cues: 12, 0 with text` for a file with image cues. The JSON holds `"imageCues": {"count": 12, "withText": 0}` for every file.
