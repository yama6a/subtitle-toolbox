# Upgrade from 1.x to 2.0

2.0 changes the PHP API and the command line tool. The tables map each 1.x call to its 2.0 call. The last table lists the changes in output and exit codes that need no change in your code.

```sh
composer require ymakhloufi/subtitle-toolbox:^2.0
```

2.0 has the same requirements as 1.x: PHP 8.2 or later, `ext-dom` and `ext-iconv`.

## New names
The calls below use these imports:

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\HearingImpairedRemover;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Resegmenter;
use SubtitleToolbox\ResegmentMode;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions; // and the other classes and enums of the write options table
use SubtitleToolbox\Parsers\CsvReadOptions;             // and the other classes of the read options table
use SubtitleToolbox\Speakers\SpeakerLabelOptions;
use SubtitleToolbox\Speakers\SpeakerStyle;
use SubtitleToolbox\Validation\ValidationRules;
```

- **`Format`**: an enum with one case per format, for example `Format::SubRip`. Its value is the format name of the command line tool, for example `'srt'`. The library takes no class names and no format name strings. See [formats.md](formats.md#the-format-enum).
- **`ReadOptions`**: every setting of one read. See [read-options.md](read-options.md).
- **`WriteOptions`**: the settings that every format shares, plus one options class for the settings of one format. See [formats.md](formats.md#write-options).

## Load, parse and write
| 1.x | 2.0 |
|:--- |:--- |
| `Subtitle::parse($content, SubRipParser::class)` | `Subtitle::fromString($content, Format::SubRip)` |
| `Subtitle::parse($content)` | `Subtitle::fromStringAutoDetectFormat($content)` |
| `Subtitle::parse(file_get_contents('movie.srt'))` | `Subtitle::loadAutoDetectFormat('movie.srt')` or `Subtitle::load('movie.srt', Format::SubRip)` |
| `Subtitle::parse($content, SubRipParser::class, 'Windows-1252')` | `Subtitle::fromString($content, Format::SubRip, new ReadOptions(encoding: 'Windows-1252'))` |
| `$subtitle->format(WebVttFormatter::class)` | `$subtitle->toString(Format::WebVtt)` |
| `file_put_contents('movie.vtt', $subtitle->format(WebVttFormatter::class))` | `$subtitle->save('movie.vtt')`. The extension picks the format. `save('movie.txt', Format::WebVtt)` names it |
| `Subtitle::detectParser($content)`, `FormatDetector::detect($content)` | `Format::detect($content)`. It returns a `Format` or null |
| `FormatRegistry::forPath('movie.sub')` | `Format::fromPath('movie.sub')` |
| `FormatRegistry::names()` | `array_map(fn (Format $f) => $f->value, Format::cases())` |
| `FormatRegistry::extensions('ass')` | `Format::Ass->extensions()` |
| `FormatRegistry::find('srt')`, `FormatRegistry::forExtension('srt')` | `Format::tryFrom('srt')` for a format name, `Format::fromPath('movie.srt')` for an extension |
| `FormatRegistry::parserClass('vobsub')`, `FormatRegistry::formatterClass('vobsub')` | `Format::VobSub->canRead()`, `Format::VobSub->canWrite()` |
| a format name from user input, such as `'srt'` | `Format::from('srt')`, or `Format::tryFrom()` for null on an unknown name |
| `MatroskaReader::open('movie.mkv')->extract(3)` | `Subtitle::loadTrack('movie.mkv', 3)` |
| `MatroskaReader::open('movie.mkv')->getSubtitleTracks()` | `Subtitle::tracks('movie.mkv')` |
| `MatroskaReader::DEFAULT_LAST_CUE_DURATION` | `ReadOptions::$lastCueDuration`, 5 s by default |
| `(new VobSubParser(file_get_contents('movie.idx'), 'de'))->parse(file_get_contents('movie.sub'))` | `Subtitle::load('movie.idx', Format::VobSub, new ReadOptions(language: 'de'))`. It reads the `.sub` file next to the `.idx` file |
| `new SubRipStreamWriter($stream, [SubtitleFormatter::OPTION_LINE_ENDING => "\r\n"])` | `new SubRipStreamWriter($stream, new WriteOptions(lineEnding: LineEnding::Crlf))`. `WebVttStreamWriter` takes the options as its third argument |
| a parser or formatter object, for example `(new SubRipParser())->parse($content)` | the classes stay public. `parse()` takes `(string $content, ReadOptions $options)`, `format()` takes `(Subtitle $subtitle, WriteOptions $options)` |
| a class that extends a formatter, such as `class MyFormatter extends SubRipFormatter` | every formatter except `SubtitleFormatter` is `final`. Call the formatter from your own class and change the string that `format()` returns |
| `SubRipFormatter::formatCueBlock()`, `WebVttFormatter::formatCueBlock()` | `@internal`. Write one cue at a time with `SubRipStreamWriter` or `WebVttStreamWriter` |
| `PodcastTranscriptFormatter::segments()` | `@internal`. Read the `segments` key of `json_decode($subtitle->toString(Format::PodcastTranscript), true)` |
| `MpSubFormatter::MPSUB_HEADER` | removed. `(new Subtitle())->toString(Format::MpSub)` returns the header without metadata, after a UTF-8 BOM |
| `SamiFormatter::DEFAULT_CLASS` | private. Its value is `'SUBTTL'` |

`FormatRegistry` and `FormatDetector` are internal now. `getFormat()` returns the format that a load or `fromString()` call read.

## Read options
No parser constructor takes an argument. Pass the setting to `ReadOptions`.

| 1.x | 2.0 |
|:--- |:--- |
| `(new SubRipParser())->setLenient()` | `new ReadOptions(lenient: true)` |
| `$parser->getWarnings()` | `$subtitle->getParseWarnings()` |
| `new MicroDvdParser(23.976)` | `new ReadOptions(fps: 23.976)` |
| `new SamiParser('ENUSCC', 10)` | `new ReadOptions(language: 'ENUSCC', lastCueDuration: 10)` |
| `new VobSubParser($idx, 'de')`, `new VobSubParser($idx, 1)` | `new ReadOptions(language: 'de', format: new VobSubReadOptions($idx))`, `new ReadOptions(track: 1, format: new VobSubReadOptions($idx))` |
| `new TmPlayerParser(4)`, `new SubViewerParser(10)`, `new LyricsParser(10)`, `new PgsParser(5)`, `new HtmlTranscriptParser(10)` | `new ReadOptions(lastCueDuration: 4)` and so on |
| `TmPlayerParser::DEFAULT_LAST_CUE_DURATION` and the same constant of 4 other parsers | `ReadOptions::$lastCueDuration`, 5 s for every format |
| `new WhisperJsonParser([WhisperJsonParser::OPTION_WORD_TIMESTAMPS => true])` | `new ReadOptions(wordTimestamps: true)`. The same for the YouTube, Podcasting 2.0 and cloud speech parsers |
| `new DeepgramParser([DeepgramParser::OPTION_SPEAKER_VOICES => true])` | `new ReadOptions(speakerVoices: true)`. The same for Whisper and the other cloud speech parsers |
| `new PodcastTranscriptParser([PodcastTranscriptParser::OPTION_KEEP_SEGMENTS => true], 10)` | `new ReadOptions(lastCueDuration: 10, format: new PodcastTranscriptReadOptions(keepSegments: true))` |
| `new CsvParser($columns, ';', 10)` | `new ReadOptions(lastCueDuration: 10, format: new CsvReadOptions($columns, ';'))` |
| `new SccParser(2)` | `new ReadOptions(format: new SccReadOptions(channel: 2))` |
| `new EbuStlParser(true)` | `new ReadOptions(format: new EbuStlReadOptions(subtractStartOfProgramme: true))` |
| `new FfMetadataChaptersParser(3600)`, and the YouTube, Podcasting 2.0 and OGM chapter parsers | `new ReadOptions(format: new ChapterReadOptions(mediaDuration: 3600))` |

The per-format read classes are in `SubtitleToolbox\Parsers`.

## Write options
Every `OPTION_*` constant of the formatters is gone. The per-format classes are in `SubtitleToolbox\Formatters\Options`. Each class name ends in `WriteOptions`, and each `frameRate` field is a `float`.

```php
// 1.x
$sub = $subtitle->format(MicroDvdFormatter::class, [
    MicroDvdFormatter::OPTION_FRAME_RATE  => 23.976,
    SubtitleFormatter::OPTION_LINE_ENDING => "\r\n",
    SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS,
]);

// 2.0
$sub = $subtitle->toString(Format::MicroDvd, new WriteOptions(
    lineEnding: LineEnding::Crlf,
    stripTags: true,
    format: new MicroDvdWriteOptions(frameRate: 23.976),
));
```

| 1.x constant | 2.0 |
|:--- |:--- |
| `SubtitleFormatter::OPTION_LINE_ENDING => "\r\n"` | `WriteOptions(lineEnding: LineEnding::Crlf)` |
| `SubtitleFormatter::OPTION_BOM => true` | `WriteOptions(bom: true)` |
| `SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS` | `WriteOptions(stripTags: true)` |
| `SubtitleFormatter::OPTION_SKIP_IMAGE_CUES` | `WriteOptions(skipImageCues: true)` |
| `AssFormatter::OPTION_KARAOKE_TAG` | `AssWriteOptions(karaokeTag: AssKaraokeTag::Fill)`. The enum also has `Instant` for `k` and `Outline` for `ko` |
| `CsvFormatter::OPTION_DELIMITER` | `CsvWriteOptions(delimiter: ';')` |
| `CsvFormatter::OPTION_TIME_FORMAT => CsvParser::TIME_COMMA` | `CsvWriteOptions(timeFormat: CsvTimeFormat::Comma)`. The enum also has `Seconds`, `Dot` and `Frames` |
| `CsvFormatter::OPTION_FRAME_RATE`, the key `'frameRate'` | `CsvWriteOptions(frameRate: 25)` |
| `CsvFormatter::OPTION_SECOND_TEXT`, `OPTION_SECOND_TEXT_HEADER` | `CsvWriteOptions(secondText: $german, secondTextHeader: 'text (de)')` |
| `CsvFormatter::OPTION_ESCAPE_FORMULAS` | `CsvWriteOptions(escapeFormulas: true)` |
| `EbuStlFormatter::OPTION_FRAME_RATE` | `EbuStlWriteOptions(frameRate: 25)` |
| `HtmlTranscriptFormatter::OPTION_PARAGRAPH_GAP` | `HtmlTranscriptWriteOptions(paragraphGap: 2.0)` |
| `IttFormatter::OPTION_FRAME_RATE` | `IttWriteOptions(frameRate: 25)` |
| `JsonFormatter::OPTION_PRETTY_PRINT`, `OPTION_WITH_FORMAT_DATA` | `JsonWriteOptions(prettyPrint: true, withFormatData: false)` |
| `MicroDvdFormatter::OPTION_FRAME_RATE`, `OPTION_WRITE_FRAME_RATE_LINE` | `MicroDvdWriteOptions(frameRate: 23.976, writeFrameRateLine: true)` |
| `MpSubFormatter::OPTION_FRAME_RATE` | `MpSubWriteOptions(frameRate: 25)` |
| `PlainTextFormatter::OPTION_JOIN_LINES`, `OPTION_JOIN_CUES`, `OPTION_PARAGRAPH_GAP`, `OPTION_WITH_TIMES` | `PlainTextWriteOptions(joinLines: false, joinCues: false, paragraphGap: 3.0, withTimes: true)` |
| `PodcastTranscriptFormatter::OPTION_WORD_SEGMENTS`, `OPTION_PRETTY_PRINT` | `PodcastTranscriptWriteOptions(wordSegments: true, prettyPrint: true)` |
| `SccFormatter::OPTION_DROP_FRAME` | `SccWriteOptions(dropFrame: false)` |
| `SubViewerFormatter::OPTION_VERSION` | `SubViewerWriteOptions(version: SubViewerVersion::V1)`. The enum also has `V2` |
| `CsvParser::TIME_SECONDS`, `TIME_DOT`, `TIME_COMMA`, `TIME_FRAMES` | `CsvTimeFormat::Seconds`, `Dot`, `Comma`, `Frames` |
| `CsvParser::TIME_FORMATS` | `CsvTimeFormat::cases()` |

## Edits that became services
Common one-step edits stay methods on `Subtitle`, for example `shift()`, `fixOverlaps()`, `mergeShortCues()` and `changeCase()`. An edit with many settings is a service with one static `apply()`. It changes the subtitle and returns a report. See [subtitle.md](subtitle.md#call-shapes).

| 1.x | 2.0 |
|:--- |:--- |
| `$subtitle->removeHearingImpaired($options)` | `HearingImpairedRemover::apply($subtitle, $options)`. `$options` is required |
| `$options->isHearingImpaired($line)` | `HearingImpairedRemover::isAnnotation($line, $options)` |
| `$subtitle->splitLongCues(new ResegmentOptions(maxCharactersPerLine: 42))` | `Resegmenter::apply($subtitle, new ResegmentOptions(mode: ResegmentMode::SplitLong, maxCharactersPerLine: 42))` |
| `$subtitle->resegmentByWords($options)` | `Resegmenter::apply($subtitle, new ResegmentOptions(mode: ResegmentMode::ByWords))` |
| `ReferenceSync::sync($german, $english, $options)->apply($german)` | `ReferenceSync::apply($german, new ReferenceSyncOptions(reference: $english))`. It returns the `SyncResult` |
| `ShotChangeTiming::apply($subtitle, $shots, new ShotChangeOptions(24))` | `ShotChangeTiming::apply($subtitle, new ShotChangeOptions(frameRate: 24, shotChanges: $shots))` |
| `ShotChangeTiming::chainGaps($subtitle, $options)` | `ShotChangeTiming::apply($subtitle, $options)` without shot changes |
| `$fixes = CommonErrorFixer::fix($subtitle, $options)` | `$fixes = CommonErrorFixer::apply($subtitle, $options)->fixes`. `$options` is required |
| `$karaoke = WordHighlight::expand($subtitle, $options)` | `WordHighlight::apply($karaoke = clone $subtitle, $options)` |
| `$ranges = ProfanityFilter::apply($subtitle, $options)` | `$ranges = ProfanityFilter::apply($subtitle, $options)->muteRanges` |
| `SpeakerLabels::toPrefix($subtitle)` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::Prefix))` |
| `SpeakerLabels::toDialogueDashes($subtitle, '- ')` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::DialogueDashes, dash: '- '))` |
| `SpeakerLabels::toColours($subtitle, $colours)` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::Colours, colours: $colours))` |
| `SpeakerLabels::fromPrefix($subtitle)` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(from: SpeakerStyle::Prefix))` |
| `SpeakerLabels::rename($subtitle, ['MAN' => 'TOM'])` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(rename: ['MAN' => 'TOM']))` |
| `$subtitle->forcedOnly()` | `$subtitle->onlyForced()` |
| `$subtitle->getErrors()` | `$subtitle->validate(ValidationRules::structure())`. `getCueIndex()` of the result is null for a subtitle without cues |

`ResegmentOptions` and `ReferenceSyncOptions` have a new first parameter, and `ShotChangeOptions` has a new second one. Pass their arguments by name, as the table does.

`ReferenceSync` and `ReferenceSyncOptions` are in `SubtitleToolbox\Sync`. `ShotChangeTiming` and `ShotChangeOptions` are in `SubtitleToolbox\Timing`.

## Command line tool
See [cli.md](cli.md) for every command and option.

| 1.x | 2.0 |
|:--- |:--- |
| `convert --case-language de` | `convert --language de` |
| `convert --replace FROM=TO --regex --ignore-case` | `convert --replace FROM=TO --replace-regex --replace-ignore-case` |
| `convert --karaoke-tag kf` | `convert --ass-karaoke-tag kf` |
| `convert --karaoke-mode`, `--karaoke-words` | removed. Use `WordHighlightOptions` in PHP |
| `validate --no-overlap`, `--no-empty-cues`, `--no-double-spaces` | `validate --check-overlap`, `--check-empty-cues`, `--check-double-spaces` |
| `validate --no-leading-or-trailing-spaces`, `--no-unbalanced-tags`, `--no-all-caps-lines` | `validate --check-leading-or-trailing-spaces`, `--check-unbalanced-tags`, `--check-all-caps-lines` |
| `--fps 25` | still works and sets each frame rate that the command has. `--input-fps`, `--output-fps` and `--video-fps` set one rate each, see [Frame rates](cli.md#frame-rates) |
| `convert movie.srt --to vtt` to write `movie.vtt` | `convert movie.srt --to vtt -o movie.vtt` |
| `convert call.json --to srt` for Deepgram JSON | `convert call.json --from deepgram --to srt`. Chapters and cloud speech JSON always need `--from` |
| none | `diff` and `dual` read the second file with `--from2` and `--track2`. `convert` and `dual` take `--in-place`. `diff`, `dual` and `hls` take `--keep-going` |

### Removed commands
2.0 removes the commands `shift`, `scale`, `fps` with its alias `sync-fps`, `fix`, `strip-sdh` and `snap`. They fail like any unknown command: exit code 2 and a pointer to the command list. Use the `retime` or `convert` call of the table.

| 1.x | 2.0 |
|:--- |:--- |
| `shift FILE --by 2 --after 60` | `retime FILE --shift 2 --shift-after 60` |
| `scale FILE --factor 1.001` | `retime FILE --scale 1.001` |
| `fps FILE --from 25 --to 23.976`, `sync-fps FILE --from 25 --to 23.976` | `retime FILE --from-fps 25 --to-fps 23.976` |
| `fix FILE --overlaps --min-gap 0.083` | `convert FILE --fix-overlaps --fix-min-gap 0.083`. Each `fix --X` option becomes `--fix-X` |
| `fix FILE --common-errors --replace-list L --list-fixes --language de` | `convert FILE --fix-common-errors --fix-replace-list L --fix-list --language de` |
| `strip-sdh FILE` | `convert FILE --sdh` |
| `strip-sdh FILE --lyrics --brackets "{}"` | `convert FILE --sdh --sdh-lyrics --sdh-brackets "{}"`. Each `strip-sdh --X` option becomes `--sdh-X` |
| `snap FILE --shot-changes F --fps 24` | `convert FILE --snap-shot-changes F --video-fps 24` |
| `snap FILE --fps 24 --snap-window 12 --min-gap-frames 2 --min-duration-frames 20 --no-chain` | `convert FILE --video-fps 24 --snap-window-frames 12 --snap-min-gap-frames 2 --snap-min-duration-frames 20 --snap-no-chain` |
| `snap FILE --fps 24` without other snap options | `convert FILE --video-fps 24 --snap-min-gap-frames 2` |

## Behaviour changes
These changes alter the output or the exit code of a call that needs no other change.

| Area | 1.x | 2.0 | To keep the 1.x result |
|:--- |:--- |:--- |:--- |
| Last cue without an end, TMPlayer | lasts 4 s | lasts 5 s, so it ends 1 s later | `new ReadOptions(lastCueDuration: 4)` |
| Last cue without an end, SCC caption that no command erases | lasts 4 s | lasts 5 s | `lastCueDuration: 4` |
| Last cue without an end, SubViewer 1, LRC, SAMI, CSV and TSV, HTML transcript, Podcasting 2.0 transcript | lasts 10 s | lasts 5 s, so it ends 5 s earlier | `lastCueDuration: 10` |
| Last cue without an end, PGS, VobSub, MKV | lasts 5 s | lasts 5 s. PGS also accepts 0 | nothing |
| Auto-detection | `Subtitle::parse($content)` and the CLI found chapters and cloud speech JSON | `Format::detect()`, `fromStringAutoDetectFormat()`, `loadAutoDetectFormat()` and the CLI without `--from` try subtitle formats only. A chapter list or a Deepgram file throws `UnknownFormatException` | name the format, for example `Subtitle::load('call.json', Format::Deepgram)` or `--from deepgram` |
| Unknown format | auto-detection threw `InvalidParserException` with error code 102 | it throws `UnknownFormatException`, a subclass of `InvalidParserException`, with error code 106 | catch `InvalidParserException` |
| Detection of JSON | a regular expression on the text | the keys of the decoded JSON. Content that starts with `{` and is not valid JSON gives null. YouTube json3 needs `tStartMs` in its first event | name the format |
| CLI output of one input | `convert movie.srt --to vtt` wrote `movie.vtt` | every command writes one input to standard output | `-o FILE` or `--output-dir DIR` |
| CLI output of 2 or more inputs | the commands that edit a file failed and asked for `--output-dir` or `--in-place` | every command writes each output next to its input, with the extension of the output format | `--output-dir` or `--in-place` |
| `convert --help` | listed every option | lists the common options and the option groups. `convert --help GROUP` lists the options of one group | `convert --help all` |
| CLI inputs | `--force` let a command write over its input | a command never overwrites an input without `--in-place`, also not with `--force`. That file fails | `--in-place` |
| Unknown options | before 1.70.5, a misspelled key or a key of another format was ignored. 1.70.5 and later threw `InvalidArgumentException` | a misspelled field, such as `new WriteOptions(lineEndings: LineEnding::Crlf)`, is a PHP `Error` for an unknown named parameter. An options class of another format, such as `new CsvWriteOptions()` for SubRip output, throws `InvalidArgumentException`. Read classes follow the same rule | fix the name, or pass the class of the format |
| Strict types | the library converted scalar values | every file declares `strict_types`. A `mapText()`, `mapLines()`, `Markup::mapTextRuns()` or `ProfanityOptions` mask callback must return a string, else it throws `TypeError`. `GlyphOcrEngine` options need their exact types, for example `['inkThreshold' => 128]` | return the documented type |
| CSV and TSV times in `hh:mm:ss:ff` | `CsvParser` threw `ParsingException` without `CsvColumns(frameRate:)`, and the CLI could not read such a file | `CsvParser` takes the frame rate of `ReadOptions::$fps` when `CsvColumns` has none. The CLI `--input-fps` and `--fps` set it | pass `CsvColumns(frameRate:)`, which wins |
| JSON output of text that is not UTF-8 | `JsonFormatter` and the Podcasting 2.0 formatters threw `JsonException` | they throw `InvalidArgumentException`, with the `JsonException` as its previous exception | catch `InvalidArgumentException` or `SubtitleToolboxException` |
| `ParseWarning::$message` | ended with " (line N)" for MicroDVD, MPSub, MPL2, TMPlayer, ASS, SubViewer, CSV, YouTube XML and HTML | holds no line suffix. `$lineNumber` holds the line | read `$lineNumber` |
| Word timestamps | `shift()`, `scale()`, `convertFrameRate()`, `syncByTwoPoints()`, `merge()` with an offset, `slice()` with `$moveToZero`, `ReferenceSync` and the CLI `retime` and `sync` kept the word timestamps in the cue text, such as `<00:00:02.000>`, at their old times | they move the word timestamps with the cues. Mute ranges of the profanity filter and the WebVTT, LRC and ASS karaoke output use the moved times | nothing. The 1.x times were wrong |
| Library JSON format data | `JsonParser` and `fromArray()` took any value in the format data. A formatter then failed with a PHP `TypeError` or `Error` | they throw `ParsingException` with the path of a format data field of the wrong type, for example `formatData.scc.dropFrame`. A lenient `JsonParser` drops the bad format data of that format, or skips the cue | fix the field |
| Wrong JSON types in the speech-to-text and Podcasting 2.0 parsers | a Whisper `segments` object, a Google `alternatives` string, or a time such as `1e400` gave a PHP `TypeError` or an infinite time | they throw `ParsingException` with the path of the field, or skip the block in lenient mode. A Podcasting 2.0 chapter `endTime` that is not a number throws, where 1.x ignored it | fix the field |
| CLI errors | a PHP `Error` such as `TypeError` stopped the tool with a PHP fatal error | the tool prints `FILE: TypeError: MESSAGE`, fails that file with exit code 1 and goes on with `--keep-going` | nothing |
| HLS segments | `HlsWebVttResult::getSegments()` and `getDurations()` returned arrays | they return generators that write each segment when you read it, so a long subtitle needs no memory for all segments. `getSegmentCount()` returns the number | `iterator_to_array($hls->getSegments())` |
| Sync limits | `ReferenceSyncOptions` and the CLI `sync` took any offset range and any number of splits, and ran out of memory on large values | `minOffset` and `maxOffset` are from -86,400 to 86,400 s and at most 7,200 s apart. `maxSplits` is from 0 to 10. A larger value throws `InvalidArgumentException`, and the CLI exits with code 2 | keep the values in these ranges |
| Image size | the PGS and VobSub parsers decoded an image of any size | an image larger than 7,680 pixels per side or 8,294,400 pixels, one 3840x2160 frame, throws `ParsingException`. `new CueImage()` and `PngDecoder::decode()` throw `InvalidArgumentException` | nothing for Blu-ray, UHD and DVD files, whose images are at most 3840x2160 |
| Stored TTML head that is not valid XML | `toString(Format::Ttml)` threw `InvalidFormatterException`, error code 101 | it throws `InvalidArgumentException`, error code 104 | catch `InvalidArgumentException` |
| Karaoke | `WordHighlight::expand()` returned a new subtitle and left its input as it was | `WordHighlight::apply()` changes the subtitle that you pass | pass `clone $subtitle` |

Code that does not declare `strict_types` itself still calls the library as before. `new SubtitleCue("1", 2)` from such a file works.
