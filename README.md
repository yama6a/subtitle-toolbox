# Subtitle Toolbox
[![Packagist version](https://img.shields.io/packagist/v/ymakhloufi/subtitle-toolbox)](https://packagist.org/packages/ymakhloufi/subtitle-toolbox)
[![CI](https://github.com/yama6a/subtitle-toolbox/actions/workflows/ci.yaml/badge.svg?branch=master)](https://github.com/yama6a/subtitle-toolbox/actions/workflows/ci.yaml)
[![Licence](https://img.shields.io/packagist/l/ymakhloufi/subtitle-toolbox)](LICENSE)

A PHP library and command line tool that reads subtitles, transcripts and chapter lists, edits their cues, and writes them out in another format. It is for PHP apps and scripts that handle subtitle files, speech-to-text output or chapter lists.

## Install
```sh
composer require ymakhloufi/subtitle-toolbox
```

| Needs | For |
|:--- |:--- |
| PHP 8.2 or later | everything |
| `ext-dom`, `ext-iconv` | everything. Composer checks them |
| `ext-mbstring`, optional | full Unicode upper and lower case. Without it, only A to Z change case |
| `ext-curl`, optional | the DeepL and Google translation engines and the `translate` command |
| `ext-zlib`, optional | writing PGS, compressed PNG images, zlib-compressed MKV tracks |
| [`yama6a/php-glyph-ocr`](https://github.com/yama6a/php-glyph-ocr), optional | the built-in OCR of PGS and VobSub bitmaps |

## Quick start
```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::load('movie.srt', Format::SubRip);

$subtitle->shift(-2.5);                                         // all cues 2.5 s earlier
$subtitle->convertFrameRate(25, 23.976);                        // subtitle for a 25 fps video, video is 23.976 fps
$subtitle->fixOverlaps(0.083);                                  // end each cue at least 0.083 s before the next one
$subtitle->wrapLines(42);                                       // at most 42 characters per line, at most 2 lines

$subtitle->save('movie.vtt');                                   // WebVTT, from the extension
```

- **Format**: the enum `Format` names each format, for example `Format::SubRip`. `Subtitle::loadAutoDetectFormat('movie.srt')` detects the format from the content. See [load and save](docs/formats.md#load-and-save) and [detection](docs/detection.md).
- **Read options**: `Subtitle::fromString($content, Format::SubRip, new ReadOptions(encoding: 'Windows-1252'))` converts the input to UTF-8. `ReadOptions` holds every parser setting. See [read options](docs/read-options.md) and [encodings](docs/encodings.md).
- **Errors**: every exception implements `SubtitleToolboxException`. See [errors](docs/errors.md).

## Supported formats
| Format | Case | Name | Extensions | Read | Write | Notes |
|:--- |:--- |:--- |:--- |:---:|:---:|:--- |
| ASS, SSA | `Ass` | `ass` | `.ass`, `.ssa` | yes | yes | karaoke tags `\k` by default, `\kf` or `\ko` with `AssOptions::$karaokeTag` |
| CSV, TSV | `Csv`, `Tsv` | `csv`, `tsv` | `.csv`, `.tsv` | yes | yes | for spreadsheets. No detection, pass `Format::Csv` |
| EBU STL | `EbuStl` | `stl` | `.stl` | yes | yes | binary, 25 or 30 fps |
| iTunes Timed Text | `Itt` | `itt` | `.itt` | yes | yes | needs a frame rate to write |
| LRC | `Lyrics` | `lrc` | `.lrc` | yes | yes | with enhanced LRC word times |
| MicroDVD | `MicroDvd` | `microdvd` | `.sub` | yes | yes | needs the frame rate of the video |
| MPL2 | `Mpl2` | `mpl2` | `.txt` | yes | yes | |
| MPSub | `MpSub` | `mpsub` | `.mpsub` | yes | yes | |
| SAMI | `Sami` | `sami` | `.smi`, `.sami` | yes | yes | one language class per parse |
| SBV | `Sbv` | `sbv` | `.sbv` | yes | yes | |
| SCC | `Scc` | `scc` | `.scc` | yes | yes | CEA-608 closed captions |
| SubRip | `SubRip` | `srt` | `.srt` | yes | yes | |
| SubViewer 1 and 2 | `SubViewer` | `subviewer` | `.sub` | yes | yes | |
| TMPlayer | `TmPlayer` | `tmplayer` | `.txt` | yes | yes | |
| TTML, IMSC, DFXP | `Ttml` | `ttml` | `.ttml`, `.dfxp`, `.xml` | yes | yes | |
| WebVTT | `WebVtt` | `vtt` | `.vtt` | yes | yes | also chapters |
| PGS | `Pgs` | `pgs` | `.sup` | yes | yes | Blu-ray bitmaps as image cues |
| VobSub | `VobSub` | `vobsub` | `.idx` with `.sub` | yes | no | DVD bitmaps as image cues |
| JSON of this library | `Json` | `json` | `.json` | yes | yes | |
| Plain text | `PlainText` | `txt` | `.txt` | no | yes | transcript |
| Whisper JSON | `Whisper` | `whisper` | `.json` | yes | no | OpenAI API, openai-whisper, faster-whisper, WhisperX, whisper.cpp |
| Cloud speech-to-text JSON | `AwsTranscribe`, `Deepgram`, `AssemblyAi`, `GoogleSpeech` | `aws-transcribe`, `deepgram`, `assemblyai`, `google-speech` | `.json` | yes | no | Amazon Transcribe, Deepgram, AssemblyAI, Google Cloud Speech-to-Text. Not detected |
| YouTube timed text | `YouTube` | `youtube` | `.json3`, `.srv3`, `.srv1` | yes | no | json3, srv1, srv2, srv3 and transcript XML |
| Podcasting 2.0 transcript JSON | `PodcastTranscript` | `podcast-transcript` | `.json` | yes | yes | |
| HTML transcript | `HtmlTranscript` | `html` | `.html`, `.htm` | yes | yes | the Podcasting 2.0 HTML format |
| YouTube chapters | `YouTubeChapters` | `ytchapter` | `.txt` | yes | yes | chapter list in a video description. Not detected |
| Podcasting 2.0 chapters | `PodcastChapters` | `podcast` | `.json` | yes | yes | not detected |
| FFmpeg metadata chapters | `FfMetadata` | `ffmeta` | `.ffmeta` | yes | yes | not detected |
| OGM chapters | `OgmChapters` | `ogm` | `.txt` | yes | yes | not detected |
| MKV and WebM tracks | | | `.mkv`, `.webm` | yes | no | `MatroskaReader` reads `S_TEXT/UTF8`, ASS, SSA, WebVTT and PGS tracks. CLI: `--track` |

**Case** is the case of the enum `Format`, for example `Format::SubRip`. **Name** is its value, and the format name for `--from` and `--to` in the command line tool. The details of each format are in [formats](docs/formats.md), [transcripts](docs/transcripts.md), [chapters](docs/chapters.md), [OCR](docs/ocr.md), [JSON](docs/json.md) and [MKV](docs/mkv.md).

## Features
- **Cues and metadata**: comments, alignment, format data, lookup by time, forced cues, statistics. See [subtitle.md](docs/subtitle.md) and [markup.md](docs/markup.md).
- **Editing**: shift, scale, frame rate, merge, slice, split, join, overlaps, line wrapping, short and long cues, shot changes, dual-language subtitles. See [editing.md](docs/editing.md).
- **Text**: search and replace, case, hearing-impaired removal, speaker labels, profanity filter with mute ranges, karaoke, OCR error fixes. See [text.md](docs/text.md).
- **Validation**: reading speed, line length and timing rules, with Netflix and BBC presets. See [validation.md](docs/validation.md).
- **Sync**: find the offset and frame rate from a reference subtitle or from the speech in the audio. See [sync.md](docs/sync.md).
- **OCR**: turn PGS and VobSub bitmaps into text in pure PHP, or with your own engine. See [ocr.md](docs/ocr.md).
- **Translation**: translate the cues with the built-in DeepL and Google engines or your own engine, and keep the tags. See [translation.md](docs/translation.md).
- **Compare**: list the cues that changed between two versions. See [compare.md](docs/compare.md).
- **Large files and streaming**: stream SRT and WebVTT cue by cue, cut WebVTT into HLS segments. See [streaming.md](docs/streaming.md) and [hls.md](docs/hls.md).
- **Broken files**: skip broken cues with a warning in place of an exception. See [lenient-parsing.md](docs/lenient-parsing.md).

[docs/README.md](docs/README.md) lists all pages.

## OCR
OCR turns the bitmaps of PGS and VobSub subtitles into text. The library uses Tesseract when it is installed, and else php-glyph-ocr.

| | Tesseract | php-glyph-ocr |
|:--- |:--- |:--- |
| Install | system package manager | `composer require yama6a/php-glyph-ocr`, about 1 MB |
| Languages | more than 100 | Latin-script fonts only |
| Correct characters on the test files | 100% | 98 to 100% |
| Time per cue | 110 to 180 ms | 110 to 140 ms |
| Memory | about 35 MB, in its own process | about 76 MB for the glyph database |

```sh
apt install tesseract-ocr tesseract-ocr-deu                          # Debian, Ubuntu
apk add tesseract-ocr tesseract-ocr-data-eng tesseract-ocr-data-deu  # Alpine
dnf install tesseract tesseract-langpack-deu                         # Fedora
brew install tesseract tesseract-lang                                # macOS, all languages
winget install UB-Mannheim.TesseractOCR                              # Windows, then add it to the PATH
docker run --rm -v "$PWD:/work" ghcr.io/yama6a/subtitle-toolbox:tesseract convert movie.sup movie.srt --ocr --ocr-language deu
```

Each language is one package, for example `tesseract-ocr-rus`. See [ocr.md](docs/ocr.md) for the options and the measurements.

## Command line tool
Composer installs `vendor/bin/subtitle-toolbox`. Every [GitHub release](https://github.com/yama6a/subtitle-toolbox/releases) also ships it as a PHAR file and as the container image `ghcr.io/yama6a/subtitle-toolbox`.

```sh
vendor/bin/subtitle-toolbox convert movie.srt movie.vtt
vendor/bin/subtitle-toolbox retime season1/ --from-fps 25 --to-fps 23.976 --in-place
vendor/bin/subtitle-toolbox convert movie.mkv movie.srt --track 3 --ocr

curl -fsSLO https://github.com/yama6a/subtitle-toolbox/releases/latest/download/subtitle-toolbox.phar
php subtitle-toolbox.phar validate movie.srt --preset netflix-en

docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/work" ghcr.io/yama6a/subtitle-toolbox convert movie.sup movie.srt --ocr
```

See [cli.md](docs/cli.md) for all commands and options.

## Contributing and releases
Pull requests are welcome. Run the tests with `composer test`.

Every merge to `master` publishes a release to Packagist, GitHub and the container registry. Each pull request carries exactly one label that sets the version bump: `major`, `minor`, `patch` or `skip-release`. CI fails a pull request without one.

## Licence
MIT, see [LICENSE](LICENSE).
