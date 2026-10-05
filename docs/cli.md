# Command line tool

`subtitle-toolbox` converts, retimes, checks and fixes subtitle files. Composer installs it as `vendor/bin/subtitle-toolbox`. It needs no package beyond the library.

```sh
vendor/bin/subtitle-toolbox convert movie.srt movie.vtt
vendor/bin/subtitle-toolbox convert season1/ --to vtt --output-dir out/ --keep-going
vendor/bin/subtitle-toolbox convert movie.sub movie.srt --fps 23.976
vendor/bin/subtitle-toolbox retime movie.srt --shift -2.5 --output movie.fixed.srt
vendor/bin/subtitle-toolbox retime *.srt --from-fps 25 --to-fps 23.976 --in-place
vendor/bin/subtitle-toolbox convert movie.srt movie.fixed.srt --timing-fix-overlaps --timing-min-gap 0.083 --structure-wrap
vendor/bin/subtitle-toolbox validate movie.srt --preset netflix-en --json
curl -s https://example.com/movie.srt | vendor/bin/subtitle-toolbox convert - --to vtt > movie.vtt
```

## Install without Composer
Every release also ships the tool as a PHAR file and as a container image.

| Form | Needs | Example |
|:--- |:--- |:--- |
| PHAR on the [GitHub release](https://github.com/yama6a/subtitle-toolbox/releases) | PHP 8.2 or later with `ext-dom`, `ext-iconv` and `ext-zlib` | `php subtitle-toolbox.phar convert in.srt out.vtt` |
| Image `ghcr.io/yama6a/subtitle-toolbox` | Docker or another container runtime | `docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/work" ghcr.io/yama6a/subtitle-toolbox:2.0.0 convert in.srt out.vtt` |
| Image `ghcr.io/yama6a/subtitle-toolbox:tesseract` | the same, for OCR with Tesseract in every language | `docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/work" ghcr.io/yama6a/subtitle-toolbox:2.0.0-tesseract convert in.sup out.srt --ocr --ocr-language deu` |

```sh
curl -fsSLO https://github.com/yama6a/subtitle-toolbox/releases/latest/download/subtitle-toolbox.phar
php subtitle-toolbox.phar --version
```

- **Version**: the PHAR file, the image tag, the Git tag and the Packagist version are the same string, for example `2.0.0`. The image also has the tags `2.0`, `2` and `latest`. The Tesseract image has the tags `2.0.0-tesseract`, `2.0-tesseract`, `2-tesseract` and `tesseract`.
- **Image**: the tool runs in `/work`, so mount your files there. `--user` makes the tool write files that you own. Without it, the tool runs as `www-data` and cannot write to most mounted folders.
- **Platforms**: the image is for `linux/amd64` and `linux/arm64`.
- **OCR**: `convert --ocr` works in every form with no extra steps, because all include php-glyph-ocr. The Tesseract image adds Tesseract with the fast models of all its languages. It is about 340 MB larger.
- **Memory**: the image sets `memory_limit` to 512 MB. The PHAR raises a `memory_limit` of 128 MB to 512 MB when you pass `--ocr`. It keeps any other value, for example from `php -d memory_limit=1G`.

## Commands
| Command | Does |
|:--- |:--- |
| `convert` | writes each input in the format of `--to` or of the output file extension, and runs OCR, text, structure and timing edits on the way, see [Convert](#convert) |
| `retime` | shifts and scales all cue times, or fits them to a video with another frame rate, see [Retime](#retime) |
| `info` | prints the format, the cue count and statistics, as text or with `--json`. Lists the tracks of an MKV or WebM file |
| `validate` | prints each broken rule, as text or with `--json`, see [Validate](#validate) |
| `sync` | retimes a subtitle to a reference subtitle or to the speech, see [Sync](#sync) |
| `diff` | lists the added, removed and changed cues of two files, see [Diff](#diff) |
| `dual` | merges two languages into one file, see [Dual](#dual) |
| `hls` | cuts a subtitle into WebVTT segments and writes an HLS playlist, see [HLS](#hls) |
| `formats` | lists the format names and extensions for `--from` and `--to` |

- **Help**: `subtitle-toolbox help CMD` and `subtitle-toolbox CMD --help` list the options of a command. For `convert`, they list the common options and the option groups, see [Order](#order).
- **Version**: `subtitle-toolbox --version` prints the installed release, for example `2.0.0`, or `dev` in a Git checkout.
- **Exit code**: see the table. A batch with a failed file exits with 3, also when another file broke a rule.

| Code | Meaning | Example |
|:--- |:--- |:--- |
| 0 | every file succeeded, and `validate` and `diff` found nothing | `validate movie.srt --preset bbc` with no broken rule |
| 1 | a result: `validate` found a broken rule, or `diff` found a difference | `diff old.srt new.srt` for 2 files that differ |
| 2 | a usage error, before the tool reads a file | an unknown option, a directory without subtitle files, `--ass-karaoke-tag` with `--to srt`, `validate --video-fps` without `--preset netflix-en`. `--ocr` without an installed OCR engine or without the data of the `--ocr-language`, see [OCR](#ocr) |
| 3 | a file could not be read or written | a missing input, a file that does not parse, an output that cannot be written, a `--mask-words` file that cannot be read |

- **Failures**: a failed file prints `FILE: MESSAGE` to standard error. The message of a library exception starts with its class, for example `ParsingException (Error #100):`. Any other PHP error prints its class and message, for example `movie.json: TypeError: ...`, and fails that file with exit code 3. An error outside a file, such as a `--mask-words` file that cannot be read, prints `Error: MESSAGE` and exits with code 3. A PHP error outside a file also prints its class.
- **Stable parts**: semantic versioning covers the binary, its commands, options, the meaning of each exit code and `--json` shapes. The text output and the messages can change in a minor release. The PHP classes in `src/Cli` are `@internal` and can change in any release. See [compatibility.md](compatibility.md).
- **Messages**: where a library message names a PHP method or option, the tool names the CLI option. For example "Call loadTrack() with one of them" becomes "Pass --track N with one of them".

## Input and output
- **Inputs**: a file, a directory, a glob such as `"season1/*.srt"`, or `-` for standard input. A directory gives its files with a known extension.
- **Input format**: `--from`, else format detection on the content, else the file extension. Chapters and cloud speech-to-text JSON need `--from`, for example `--from deepgram` or `--from ffmeta-chapters`. `--from` and `--to` also take the 1.x names `ytchapter`, `podcast`, `ogm` and `ffmeta`. The tool reads like `Subtitle::loadAutoDetectFormat()`, see [formats.md](formats.md#load-and-save).
- **Output**: `-o` or `--output` for one file, `--output-dir`, or `--in-place`. `--output -` writes standard output. `convert`, `retime`, `sync` and `dual` take all 4. `hls` takes only `--output-dir`.
- **Default output**: without these options, one input goes to standard output. With 2 or more inputs, each output goes next to its input, with the extension of the output format. The tool counts the inputs after it expands directories and globs.

| Call | Writes |
|:--- |:--- |
| `convert movie.srt --to vtt` | standard output |
| `convert movie.srt --to vtt -o movie.vtt` | `movie.vtt` |
| `convert a.srt b.srt --to vtt` | `a.vtt` and `b.vtt` |
| `retime a.srt b.srt --shift 1` | nothing. The command fails, because each output would overwrite its input |
| `retime a.srt b.srt --shift 1 --in-place` | `a.srt` and `b.srt` |

- **Inputs stay**: the tool never overwrites an input file without `--in-place`, not even with `--force`. Such a file fails with a message that names `--in-place`, `-o` and `--output-dir`. With `--keep-going`, the other files still get written. The `.sub` file of a VobSub input and the files of options such as `--mask-words` and `--reference` count as inputs.
- **One writer per file**: before it reads the first input, the tool fails with exit code 2 and writes nothing when two inputs would write the same output file, for example `convert a/movie.srt b/movie.vtt --to vtt --output-dir out`. A file passed twice, such as `movie.srt ./movie.srt`, counts once.
- **Standard input**: `-` takes `-o FILE`, not `--output-dir`. With `--output-dir`, the tool fails with exit code 2.
- **Overwrite**: the tool overwrites another existing file only with `--force`.
- **Batch**: the tool prints one line per file and a summary. It stops at the first failed file, unless you pass `--keep-going`. Every command that reads a file takes `--keep-going`, also `diff`, `dual` and `hls`, which read one input.
- **Option names**: `--no-X` always turns X off, for example `--no-bom`. A time option is in seconds, unless its name ends in `-frames`.
- **Encoding**: `--encoding` names the encoding of the input, for example `Windows-1252`. See [encodings.md](encodings.md).
- **Output bytes**: `--line-ending lf|crlf`, `--bom` and `--no-bom`.
- **Broken files**: `--lenient` skips or repairs broken cues and prints a warning for each file that a command reads, also a second file or a `--reference`, see [lenient-parsing.md](lenient-parsing.md).
- **Frame rate**: see [Frame rates](#frame-rates).
- **Word timestamps**: `--word-timestamps` keeps the word times of the speech-to-text JSON formats, YouTube timed text and Podcasting 2.0 transcripts. `--structure-resegment`, `--karaoke` and `--ass-karaoke-tag` turn it on.
- **MKV and WebM**: `--track` picks a subtitle track, see [MKV and WebM](#mkv-and-webm).
- **Image cues**: `--skip-image-cues` leaves out image cues without text in place of failing.

## Frame rates
| Option | Sets | Commands |
|:--- |:--- |:--- |
| `--input-fps RATE` | the frame rate of a MicroDVD input without a `{1}{1}<fps>` first line, and of CSV or TSV times in `hh:mm:ss:ff`, as `MicroDvdReadOptions::$frameRate` and `CsvReadOptions::$frameRate` | all that read a file |
| `--output-fps RATE` | the frame rate of MicroDVD and iTT output, as `MicroDvdWriteOptions::$frameRate` and `IttWriteOptions::$frameRate` | all that write a file |
| `--video-fps RATE` | the frame rate of the video for the frame rules, see [Timing](#timing) and [Validate](#validate) | `convert`, `validate` |
| `--fps RATE` | each of the 3 options above that the command has | all that read a file |

- **Override**: a specific option wins over `--fps`. `convert movie.sub new.sub --fps 25 --output-fps 23.976` reads at 25 fps and writes at 23.976 fps.
- **Output default**: without `--output-fps`, MicroDVD output takes the frame rate of a MicroDVD input, and iTT output the frame rate of an iTT input.
- **Formats**: `--from` and `--to` always name formats. `retime` changes the frame rate with `--from-fps` and `--to-fps`.

## Formats and file extensions
Run `subtitle-toolbox formats` for the list. When two formats share an extension, the first one in the list owns it.

- **Output extension**: when the input format also uses the extension of the output file, the output keeps the input format. So an MPL2 `film.txt` converts to MPL2 in `out.txt`. Otherwise the owner of the extension decides: an SRT input and `out.txt` give plain text. A Whisper JSON input and `out.json` give the library JSON, because the tool cannot write Whisper JSON.
- **`.sub`**: MicroDVD. Pass `--from subviewer` for SubViewer. A directory skips a `.sub` file that has an `.idx` file next to it.
- **VobSub**: pass the `.idx` file. The tool reads the `.sub` file next to it. Standard input does not work.
- **`.json` and `.txt` input**: format detection finds the library JSON, Whisper JSON, YouTube json3, Podcasting 2.0 transcripts, MPL2 and TMPlayer by their content. Other `.json` and `.txt` input fails. Pass `--from` for chapters and cloud speech-to-text JSON.
- **Other output formats**: pass `--to`, for example `--to mpl2`, `--to podcast-transcript` or `--to youtube-chapters`.
- **CSV and TSV**: TSV output has tabs between the cells. CSV output from a TSV input has commas. Other CSV output keeps the delimiter of the input table.

## MKV and WebM
Every command reads a subtitle track of an MKV or WebM file with [`Subtitle::loadTrack()`](mkv.md). `--track` takes the track number that `info` lists.

```sh
vendor/bin/subtitle-toolbox info movie.mkv
vendor/bin/subtitle-toolbox convert movie.mkv movie.srt --track 3
vendor/bin/subtitle-toolbox convert movie.mkv movie.srt --track 5 --ocr
```

```
movie.mkv
  Container: matroska
  Track 3: S_TEXT/UTF8, de, "Deutsch (Forced)", forced
  Track 4: S_TEXT/ASS, eng, "English", default
  Track 5: S_HDMV/PGS, eng
```

- **Detection**: the tool knows an MKV or WebM file by its first 4 bytes, not by its extension. Standard input works too.
- **Track**: a file with one subtitle track needs no `--track`. For a file with more, the tool fails and lists the tracks.
- **Format**: an `S_TEXT/UTF8` track is SubRip, ASS and SSA tracks are ASS, `S_TEXT/WEBVTT` is WebVTT and `S_HDMV/PGS` is PGS. Without `--to`, the output keeps this format. `convert movie.mkv --to srt --track 3 --output-dir out` writes `out/movie.srt`.
- **Second file**: `diff` and `dual` read the track of their second file with `--track2`, for example `diff old.mkv new.mkv --track 3 --track2 8`.
- **Info**: without `--track`, `info` lists the tracks of a file whose extension names no subtitle format, such as `.mkv` and `.webm`. The JSON object has `file`, `container` and `tracks`. Each track has `number`, `codecId`, `language`, `name`, `default` and `forced`. With `--track`, `info` prints the statistics of the track.
- **Directories**: a directory argument skips MKV and WebM files. Pass them by name or with a glob.
- **Errors**: `S_VOBSUB` tracks, bzlib and LZO compression and encryption fail, see [mkv.md](mkv.md).

## Retime
`retime` changes the cue times with one or more edits. It applies them in this order: `--shift`, `--scale`, then `--from-fps` and `--to-fps`. The word timestamps in the cue text move with the cues.

```sh
vendor/bin/subtitle-toolbox retime trip.srt --shift -1.5 --scale 1.001 -o trip.fixed.srt
vendor/bin/subtitle-toolbox retime movie.sub --from-fps 25 --to-fps 23.976 --input-fps 25 --to srt -o movie.srt
```

| Option | Calls |
|:--- |:--- |
| `--shift SECONDS` | [`shift()`](editing.md#retiming) with the seconds to add to every time. A negative value shows the cues earlier |
| `--shift-after SECONDS` | `shift()` with `$fromTime`, so only the cues from this time move. Needs `--shift` |
| `--scale FACTOR` | `scale()`. `--scale 1.001` fixes a subtitle that drifts 3.6 s per hour |
| `--from-fps RATE`, `--to-fps RATE` | `convertFrameRate()`. `--from-fps 25 --to-fps 23.976` fits a subtitle for a 25 fps release to a 23.976 fps video |

- **Negative times**: a time that becomes negative becomes 0.

## Convert
`convert` reads each input, runs the edits of its options, and writes the result in the format of `--to` or of the output file extension. Without both, the output keeps the input format. One call can run OCR, fix text, strip SDH, retime and convert:

```sh
vendor/bin/subtitle-toolbox convert movie.sup movie.srt --ocr --errors-fix --sdh --shift -1.5
vendor/bin/subtitle-toolbox convert lecture.json lecture.srt --structure-resegment --timing-min-duration 1
vendor/bin/subtitle-toolbox convert song.json song.ass --ass-karaoke-tag kf
vendor/bin/subtitle-toolbox convert season1/*.srt --errors-fix --in-place
```

- **Output file argument**: `convert IN OUT` reads `IN` and writes `OUT` only without `--to`, `-o`, `--output-dir` and `--in-place`. With one of them, both arguments are inputs.

### Order
`convert` always runs the edits in this order, whatever the order of the options. The options form groups. `convert --help GROUP` lists the options of one group, and `convert --help all` lists every option. A word after `--help` that holds a dot or a slash, or names a file, is no group, so `convert in.srt -h out.srt` prints the convert help. A group prefix is the group name, for example `--structure-wrap` and `--timing-min-gap`. An option that turns a default off puts `--no-` before the prefix, for example `--no-snap-chain`.

| Step | Group | Options | Why here |
|:--- |:--- |:--- |:--- |
| 1. Read | | input options | |
| 2. Forced | `forced` | `--forced-only` | OCR then reads only the cues that stay |
| 3. OCR | `ocr` | `--ocr` | the later steps need text |
| 4. Text | `errors`, `sdh`, `replace`, `text` | `--errors-fix`, `--sdh`, `--replace`, `--speakers`, `--case`, `--strip-tags` | SDH changes the line lengths, so it runs before wrapping |
| 5. Structure | `structure` | `--structure-resegment`, `--structure-unwrap`, `--structure-merge-short`, `--structure-split-long`, `--structure-wrap`, `--structure-merge-duplicates` | |
| 6. Timing | `retime`, `snap`, `timing` | `--shift`, `--scale`, `--from-fps` and `--to-fps`, `--snap-shot-changes`, `--timing-fix-overlaps`, `--timing-min-duration` | splits in step 5 create new cues |
| 7. Masking | `masking` | `--mask-words` | the mute ranges of `--mute-edl` and `--mute-filter` need the final times |
| 8. Karaoke | `karaoke` | `--karaoke` | it multiplies the cues |
| 9. Write | `ass` | output options, `--ass-karaoke-tag` | |

### OCR and forced cues
| Option | Effect |
|:--- |:--- |
| `--ocr` | reads the text of image cues, see [OCR](#ocr) |
| `--ocr-engine ENGINE` | `tesseract` or `glyph`. Default: `tesseract` when it is installed |
| `--ocr-language CODE` | the Tesseract language, for example `deu` or `deu+eng`. Default: `eng` |
| `--ocr-database FILE` | the `.nocr` glyph database for `--ocr`. It selects the glyph engine |
| `--forced-only` | keeps only the [forced cues](subtitle.md#forced-cues) |

### Text
| Option | Effect |
|:--- |:--- |
| `--errors-fix` | [`CommonErrorFixer::apply()`](text.md#fixing-common-errors) with all default fixes |
| `--errors-replace-list FILE` | adds a Subtitle Edit OCR replace list to `--errors-fix` |
| `--errors-list-fixes` | prints each change of `--errors-fix` to standard error, for example `movie.srt: cue 15: ocrLowercaseL: "lt's late." -> "It's late."`. A byte that is not valid UTF-8 prints as U+FFFD |
| `--sdh` | removes everything that [`HearingImpairedRemover::apply()`](text.md#hearing-impaired-annotations) removes by default. A cue with no text left goes |
| `--sdh-keep-square-brackets`, `--sdh-keep-parentheses`, `--sdh-keep-speaker-labels`, `--sdh-keep-music-lines` | turns off one rule of `--sdh` |
| `--sdh-any-case-labels` | also removes speaker labels that are not upper case, such as `Baker:` |
| `--sdh-lyrics` | also removes text between two music symbols |
| `--sdh-brackets PAIR` | also removes text between this pair, for example `"{}"` or `"**"`. Repeatable |
| `--replace FROM=TO` | [`replaceText()`](text.md#transforms) on the text between tags. Repeatable. The first `=` ends FROM |
| `--replace-regex` | reads each FROM as a regular expression with delimiters, for example `--replace '/\.{4,}/=...'` |
| `--replace-ignore-case` | matches FROM in any case |
| `--speakers MODE` | `prefix`, `dashes`, `colors` or `from-prefix`. Calls `SpeakerLabels::apply()` with `to: SpeakerStyle::Prefix`, `DialogueDashes` or `Colors`, or with `readPrefixes: true`, and the other options at their defaults, see [Speakers](text.md#speakers) |
| `--case MODE` | `upper`, `lower` or `sentence`, with `changeCase()` |
| `--strip-tags` | removes all formatting tags, such as `<i>` and `<font>` |
| `--language CODE` | the language of `--case` and `--errors-fix`, for example `en`, `de-AT` or `tr`. `tr` and `az` map `i` to `İ` and `ı` to `I`. Without it, `--errors-fix` takes the `language` metadata |

### Masking
| Option | Effect |
|:--- |:--- |
| `--mask-words FILE` | masks the words of a word file, as [`ProfanityFilter::apply()`](text.md#profanity-filter) does |
| `--mask STYLE` | `stars` (default), `first-letter`, `remove`, or `none`. `none` keeps the text and only finds the times for `--mute-edl` and `--mute-filter` |
| `--mute-edl FILE` | writes the times of the matches to an EDL file with [`MuteRange::toEdl()`](text.md#profanity-filter), for Kodi and MPlayer |
| `--mute-filter FILE` | writes the FFmpeg volume filter of `MuteRange::toFfmpegVolumeFilter()` |
| `--mute-padding SECONDS` | widens each time range on both sides, default 0 |

```sh
vendor/bin/subtitle-toolbox convert movie.srt clean.srt --mask-words words.txt --mute-filter mute.txt --mute-padding 0.1
ffmpeg -i movie.mp4 -af "$(cat mute.txt)" -c:v copy clean.mp4
```

- **Mute files**: they need `--mask-words` and one input file. They hold the times after `--shift`, `--scale`, snapping and the timing fixes. Without `--force`, the tool does not overwrite them.
- **Mute file names**: `--mute-edl` and `--mute-filter` must name 2 different files. Neither may name the subtitle output or a file that the command reads, not even with `--force`. Else the tool fails with exit code 2 before it writes a file.
- **No match**: the filter file is empty. Then leave out `-af`.

### Structure
[editing.md](editing.md) describes each method.

| Option | Calls |
|:--- |:--- |
| `--structure-resegment` | `Resegmenter::apply()` with `ResegmentMode::ByWords`. `--structure-max-word-gap` sets `maxWordGap`, default 0.6 s. It turns on `--word-timestamps` |
| `--structure-unwrap` | `unwrapLines()` |
| `--structure-merge-short` | `mergeShortCues()` with the default options |
| `--structure-split-long` | `Resegmenter::apply()` with `ResegmentMode::SplitLong` and the default options |
| `--structure-wrap` | `wrapLines()` with `--structure-max-cpl` and `--structure-max-lines` |
| `--structure-merge-duplicates` | `removeDuplicateCues()` |
| `--structure-max-cpl CHARS` | `maxCharactersPerLine` of `--structure-resegment`, `--structure-merge-short`, `--structure-split-long` and `--structure-wrap`, default 42 |
| `--structure-max-lines LINES` | `maxLinesPerCue` of `--structure-resegment`, `--structure-merge-short`, `--structure-split-long` and `--structure-wrap`, default 2 |

- **Limits without their fix**: `--structure-max-cpl`, `--structure-max-lines` and `--timing-min-gap` alone are a usage error, exit code 2. The message names the fix options that use them.

### Timing
`--shift`, `--shift-after`, `--scale`, `--from-fps` and `--to-fps` work as in [Retime](#retime).

| Option | Calls |
|:--- |:--- |
| `--snap-shot-changes FILE` | [`ShotChangeTiming::apply()`](editing.md#shot-changes-and-gaps) with the shot changes of the file: the log of the FFmpeg `showinfo` filter, or one time per line in seconds or `hh:mm:ss.mmm` |
| `--video-fps RATE` | `frameRate`, the frame rate of the shot changes and of the frame options. Required with the `--snap-` options |
| `--snap-window-frames FRAMES` | `snapWindowFrames`, default half a second |
| `--snap-min-gap-frames FRAMES` | `minGapFrames`, default 2 |
| `--snap-min-duration-frames FRAMES` | `minDurationFrames`, default 20 |
| `--no-snap-chain` | `chain: false` |
| `--timing-fix-overlaps` | `fixOverlaps()` with `--timing-min-gap` seconds, default 0 |
| `--timing-min-duration SECONDS` | `extendShortCues()` with `--timing-min-gap` |
| `--timing-min-gap SECONDS` | the gap of `--timing-fix-overlaps` and `--timing-min-duration` |

```sh
ffmpeg -i movie.mp4 -vf "select='gt(scene,0.3)',showinfo" -f null - 2> scenes.log
vendor/bin/subtitle-toolbox convert movie.srt movie.timed.srt --video-fps 24 --snap-shot-changes scenes.log
```

- **Gaps only**: without `--snap-shot-changes`, a `--snap-` option such as `--snap-min-gap-frames 2` only closes small gaps.
- **Frame rates**: `--input-fps` sets the frame rate of a MicroDVD input on its own.

### Karaoke and ASS output
| Option | Effect |
|:--- |:--- |
| `--karaoke` | writes one cue per word with the active word styled, with [`WordHighlight::apply()`](text.md#word-highlight-and-karaoke) |
| `--karaoke-style TAG` | `b`, `i`, `u` (default), `s` or `'font color="#ffff00"'` |
| `--ass-karaoke-tag TAG` | `k` (default), `kf` or `ko`, the ASS tag for word timestamps, see [formats.md](formats.md#ass-and-ssa). Needs ASS output. Pass only one of `--karaoke` and `--ass-karaoke-tag` |

- **Library only**: the cumulative mode and the word limit of `WordHighlightOptions` have no option. Call `WordHighlight::apply()` for them.

## JSON output
`info`, `validate` and `diff` print JSON with `--json`: a list with one object for each input, also for one input. For `diff`, the list holds one object for its pair of files. A file that fails has no object, so the list is `[]` when all files fail.

| Command | Object |
|:--- |:--- |
| `info` | `file`, `format`, `metadata`, `statistics`, `imageCues` and `warnings`. `statistics` is `SubtitleStatistics::toArray()`, with `gaps` and a `mostUsedWords` list of `{"word", "count"}` |
| `info` of an MKV or WebM file without `--track` | `file`, `container` with the value `matroska`, and `tracks` |
| `validate` | `file`, `format`, `valid`, `violations` and `warnings`. A violation has `cueIndex`, `rule`, `value`, `infinite` and `limit` |
| `diff` | `oldFile`, `newFile`, `equal`, `differences`, `oldWarnings` and `newWarnings`. A difference has `kind`, `oldIndex`, `newIndex`, `old` and `new`. A cue has `start`, `end`, `lines` and `forced` |

- **Indexes**: `cueIndex`, `oldIndex`, `newIndex` and `blockIndex` start at 0, as in the library. The text output counts cues from 1.
- **Standard input**: `file`, `oldFile` and `newFile` hold `-` for standard input. The text output prints `stdin`.
- **Limits**: `limit` is null only for a rule without a number limit, such as `noOverlap`.
- **Infinite values**: a cue with text and a duration of 0 has infinite characters per second. Its violation has `"infinite": true`, and `value` holds the largest JSON number, `1.7976931348623157e+308`. The text output prints `INF`.
- **Warnings**: a list of the parse warnings of the file, empty without `--lenient`. A warning has `lineNumber`, `blockIndex`, `message` and `action`, see [lenient-parsing.md](lenient-parsing.md).

## Info
- **Warnings**: with `--lenient`, `info` prints `Warnings: 1` for a file with one broken cue.
- **MKV and WebM**: see [MKV and WebM](#mkv-and-webm).

## Validate
`--preset` takes `netflix-en` or `bbc`, see [validation.md](validation.md#presets). A rule option overrides the value of the preset. `--video-fps` sets the frame rate for the 2-frame gap of `netflix-en`, default 23.976.

| Option | Rule |
|:--- |:--- |
| `--max-cps`, `--max-cpl`, `--max-lines` | `maxCharactersPerSecond`, `maxCharactersPerLine`, `maxLinesPerCue` |
| `--min-duration`, `--max-duration`, `--min-gap` | `minDuration`, `maxDuration`, `minGap` |
| `--max-wpm`, `--min-seconds-per-word` | `maxWordsPerMinute`, `minSecondsPerWord` |
| `--max-speakers`, `--dialogue-dash STYLE`, `--allowed-characters CHARS` | `maxSpeakersPerCue`, `dialogueDashStyle`, `allowedCharacters` |
| `--check-overlaps`, `--check-empty-cues`, `--check-double-spaces` | `noOverlap`, `noEmptyCues`, `noDoubleSpaces` |
| `--check-leading-or-trailing-spaces`, `--check-unbalanced-tags`, `--check-all-caps-lines` | `noLeadingOrTrailingSpaces`, `noUnbalancedTags`, `noAllCapsLines` |

```sh
vendor/bin/subtitle-toolbox validate movie.srt --preset bbc --check-unbalanced-tags --dialogue-dash '- '
```

## Sync
`sync` finds the scale and the offset with [`ReferenceSync`](sync.md), applies them and prints them to standard error.

```sh
vendor/bin/subtitle-toolbox sync movie.de.srt --reference movie.en.srt -o movie.de.synced.srt

ffmpeg -i movie.mkv -af silencedetect=noise=-30dB:d=0.4 -f null - 2> silence.log
vendor/bin/subtitle-toolbox sync movie.de.srt --silence-log silence.log --media-duration 5400 -o movie.de.synced.srt
```

```
movie.de.srt: scale 1.04271, offset -2.3 s, score 0.89
```

| Option | Sets |
|:--- |:--- |
| `--reference FILE` | the subtitle in sync with the video, in any format that the tool reads. A Whisper JSON transcript of the audio also works |
| `--silence-log FILE`, `--media-duration SECONDS` | the speech in an FFmpeg `silencedetect` log as the reference, with [`SpeechReference`](sync.md#sync-to-speech) |
| `--min-offset SECONDS`, `--max-offset SECONDS` | `minOffset` and `maxOffset`, default -60 and 60. From -86400 to 86400 and at most 7200 apart, see [sync.md](sync.md#sync-to-a-reference-subtitle) |
| `--no-scale` | `searchScale: false` |
| `--max-splits N`, `--split-penalty SCORE` | `maxSplits` from 0 to 10, default 0, and `splitPenalty`, default 0.1 |

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
| `--from2 FORMAT`, `--track2 NUMBER` | the format and the MKV or WebM track of the new file. `--from` and `--track` apply to the old file |
| `--json` | prints JSON, see [JSON output](#json-output) |

## Dual
`dual` merges a primary and a secondary subtitle with [`DualSubtitle::fromPair()`](editing.md#dual-subtitles). The output has the format of the primary file, unless `--to` or the `--output` extension sets another one.

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
| `--from2 FORMAT`, `--track2 NUMBER` | the format and the MKV or WebM track of the secondary file. `--from` and `--track` apply to the primary file |

- **Output**: one result, so it goes to standard output unless `-o`, `--output-dir` or `--in-place` sets a file. `--in-place` overwrites the primary file.

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
- **Names**: `hls` fails with exit code 2 before it writes a file when `--playlist` matches `--pattern`, for example `--playlist sub0.vtt`. It also fails when the playlist or a segment would overwrite the input, also with `--force`.

## OCR
`convert --ocr` reads the image cues of PGS and VobSub files before it writes the output. It uses [Tesseract](ocr.md#tesseract) when the `tesseract` program is on the `PATH`, and else [php-glyph-ocr](ocr.md#php-glyph-ocr).

```sh
vendor/bin/subtitle-toolbox convert movie.sup movie.srt --ocr
vendor/bin/subtitle-toolbox convert movie.sup movie.srt --ocr --ocr-language deu
vendor/bin/subtitle-toolbox convert movie.sup movie.srt --ocr --ocr-engine glyph
vendor/bin/subtitle-toolbox convert movie.idx movie.srt --ocr --ocr-database my-font.nocr
```

- **Engine**: `--ocr-engine` forces one engine. A forced engine that is not installed stops the tool with exit code 2 and an install hint.
- **Language**: `--ocr-language` takes Tesseract language codes. A language without installed data stops the tool before the first file with exit code 2 and lists the installed languages. The glyph engine ignores the option and prints a warning.
- **Database**: `--ocr-database` loads a `.nocr` file in place of the subtitle fonts database. See [Training a database](ocr.md#training-a-database).
- **No engine**: without Tesseract and php-glyph-ocr, `--ocr` stops with exit code 2 and prints the install commands of both.
- **Progress**: the tool prints `movie.sup: OCR 100/1500` to standard error after every 100 image cues and after the last one.
- **Memory**: with php-glyph-ocr, a 1,500-cue PGS file needs up to 170 MB, above the default `memory_limit` of 128 MB. Run `php -d memory_limit=512M vendor/bin/subtitle-toolbox convert movie.sup movie.srt --ocr` for long files.
- **Info**: `info` prints `Image cues: 12, 0 with text` for a file with image cues. The JSON holds `"imageCues": {"count": 12, "withText": 0}` for every file.
