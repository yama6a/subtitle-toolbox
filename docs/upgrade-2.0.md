# Upgrade from 1.x to 2.0

2.0 changes the PHP API and the command line tool. The tables map each 1.x call to its 2.0 call. The last table lists the changes in output and exit codes that need no change in your code.

```sh
composer require ymakhloufi/subtitle-toolbox:^2.0
```

2.0 has the same requirements as 1.x: PHP 8.2 or later, `ext-dom` and `ext-iconv`.

[compatibility.md](compatibility.md) says what semantic versioning covers in 2.x.

## New names
The calls below use these imports:

```php
use SubtitleToolbox\CueLimits;
use SubtitleToolbox\DialogueDashStyle;
use SubtitleToolbox\Format;
use SubtitleToolbox\HearingImpaired\HearingImpairedRemover;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Resegmenting\Resegmenter;
use SubtitleToolbox\Resegmenting\ResegmentMode;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions; // and the other classes and enums of the write options table
use SubtitleToolbox\Parsers\Options\CsvReadOptions;  // and the other classes of the read options table
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
| the format names `'ytchapter'`, `'podcast'`, `'ogm'` and `'ffmeta'`, for example in `FormatRegistry::find()` | `Format::YouTubeChapters`, `Format::PodcastChapters`, `Format::OgmChapters` and `Format::FfMetadataChapters`. Their values are `'youtube-chapters'`, `'podcast-chapters'`, `'ogm-chapters'` and `'ffmeta-chapters'`. `Format::tryFrom('ytchapter')` returns null |
| the format name `'youtube'`, `YouTubeTimedTextParser::class` | `Format::YouTubeTimedText`. Its value stays `'youtube'`. YouTube chapters are `Format::YouTubeChapters` |
| `FormatRegistry::find('srt')`, `FormatRegistry::forExtension('srt')` | `Format::tryFrom('srt')` for a format name, `Format::fromPath('movie.srt')` for an extension |
| `FormatRegistry::parserClass('vobsub')`, `FormatRegistry::formatterClass('vobsub')` | `Format::VobSub->canRead()`, `Format::VobSub->canWrite()` |
| a format name from user input, such as `'srt'` | `Format::from('srt')`, or `Format::tryFrom()` for null on an unknown name |
| `MatroskaReader::open('movie.mkv')->extract(3)` | `Subtitle::loadTrack('movie.mkv', 3)`. Its second parameter is `$trackNumber`, as in `extract()` |
| `MatroskaReader::open('movie.mkv')->getSubtitleTracks()` | `Subtitle::tracks('movie.mkv')` |
| `MatroskaReader::DEFAULT_LAST_CUE_DURATION` | `ReadOptions::$lastCueDuration`, 5 s by default |
| `(new VobSubParser(file_get_contents('movie.idx'), 'de'))->parse(file_get_contents('movie.sub'))` | `Subtitle::load('movie.idx', Format::VobSub, new ReadOptions(format: new VobSubReadOptions(language: 'de')))`. It reads the `.sub` file next to the `.idx` file |
| `new SubRipStreamWriter($stream, [SubtitleFormatter::OPTION_LINE_ENDING => "\r\n"])` | `new SubRipStreamWriter($stream, new WriteOptions(lineEnding: LineEnding::Crlf))`. `WebVttStreamWriter` takes the same arguments |
| `new WebVttStreamWriter($stream, $reader->getHeader(), $options)` | `new WebVttStreamWriter($stream, new WriteOptions(), $reader->getHeader())`, or `new WebVttStreamWriter($stream, header: $reader->getHeader())`. The options come second, as in `SubRipStreamWriter` |
| a parser or formatter object, for example `(new SubRipParser())->parse($content)` | the classes stay public. `parse()` takes `(string $content, ReadOptions $options)`, `format()` takes `(Subtitle $subtitle, WriteOptions $options)` |
| a class that extends a parser, such as `class MyParser extends SubRipParser` | every parser except `SubtitleParser` is `final`. Only the library extends `SubtitleParser`, and its protected members are not API. Call the parser from your own class and change the `Subtitle` that `parse()` returns |
| a class that extends a formatter, such as `class MyFormatter extends SubRipFormatter` | every formatter except `SubtitleFormatter` is `final`. Only the library extends `SubtitleFormatter`, and its protected members are not API. Call the formatter from your own class and change the string that `format()` returns |
| `SubRipParser::splitIntoBlocks()`, `parseBlock()`, `parseCueBlock()`, and the same `WebVttParser` methods with `numberedBlocks()`, `parseHeader()` and `parseSettings()` | `@internal`. Read one cue at a time with `SubRipStreamReader` or `WebVttStreamReader` |
| `CsvParser::detectDelimiter()`, `records()`, `parseTime()` | private. `parse()` keeps the detected delimiter in `findFormatData('csv')['delimiter']` |
| the constants and static helpers of `EbuStlParser`, such as `GSI_FIELDS`, `LANGUAGES` and `readGsi()` | removed from the parser. Keep your own copy of the values you need |
| the namespace constants of `TtmlParser`, such as `NAMESPACE_TTML` | removed from the parser. Use the namespace URI, for example `'http://www.w3.org/ns/ttml'` |
| `IttParser` as a `TtmlParser`, for example `$parser instanceof TtmlParser` | `IttParser` extends `SubtitleParser` only. Test `$subtitle->getFormat()` for `Format::Itt` instead |
| `AssParser::ASS_STYLE_FORMAT`, `SSA_STYLE_FORMAT`, `ASS_EVENT_FORMAT`, `SSA_EVENT_FORMAT` | `@internal` or private. `findFormatData('ass')['styleFormat']` and `['eventFormat']` hold the fields of a parsed file |
| `LyricsParser::REGEX` | removed |
| `SubRipFormatter::formatCueBlock()`, `WebVttFormatter::formatCueBlock()` | `@internal`. Write one cue at a time with `SubRipStreamWriter` or `WebVttStreamWriter` |
| `PodcastTranscriptFormatter::segments()` | `@internal`. Read the `segments` key of `json_decode($subtitle->toString(Format::PodcastTranscript), true)` |
| `MpSubFormatter::MPSUB_HEADER` | removed. `(new Subtitle())->toString(Format::MpSub)` returns the header without metadata, after a UTF-8 BOM |
| `SamiFormatter::DEFAULT_CLASS` | private. Its value is `'SUBTTL'` |
| `getFormatData('sub')`, `'smi'`, `'ffmetadata'`, `'chapters'` for Podcasting 2.0 chapters, `'podcast'` for Podcasting 2.0 transcripts | `findFormatData('microdvd')`, `'sami'`, `'ffmeta-chapters'`, `'podcast-chapters'`, `'podcast-transcript'`. The key is the value of the `Format` case |
| `IttParser::FORMAT`, `LyricsParser::FORMAT`, `SccParser::FORMAT`, `SubViewerParser::FORMAT`, `TtmlParser::FORMAT`, `WebVttParser::FORMAT` | `FORMAT_DATA_KEY`. These parsers have no `FORMAT_DATA_KEY`: HTML transcript, JSON, MPL2, OGM chapters, PGS, SBV, TMPlayer, VobSub and YouTube chapters |
| library JSON with the old format data keys, read with `fromArray()` or `JsonParser` | rename the keys in the JSON before you read it. 2.0 keeps the data under the old key, and no formatter reads it |

`FormatRegistry` and `FormatDetector` are internal now. `getFormat()` returns the format that a load or `fromString()` call read.

## Read options
No parser constructor takes an argument. Pass the setting to `ReadOptions`.

| 1.x | 2.0 |
|:--- |:--- |
| `(new SubRipParser())->setLenient()` | `new ReadOptions(lenient: true)` |
| `(new SubRipStreamReader())->setLenient()`, the same for `WebVttStreamReader` | `new SubRipStreamReader(new ReadOptions(lenient: true))`. `getWarnings()` is part of the `CueStreamReader` interface |
| `$parser->getWarnings()` | `$subtitle->getParseWarnings()` |
| `new MicroDvdParser(23.976)` | `new ReadOptions(format: new MicroDvdReadOptions(frameRate: 23.976))` |
| `new SamiParser('ENUSCC', 10)` | `new ReadOptions(lastCueDuration: 10, format: new SamiReadOptions(languageClass: 'ENUSCC'))` |
| `new VobSubParser($idx, 'de')`, `new VobSubParser($idx, 1)` | `new ReadOptions(format: new VobSubReadOptions($idx, language: 'de'))`, `new ReadOptions(format: new VobSubReadOptions($idx, track: 1))` |
| `new TmPlayerParser(4)`, `new SubViewerParser(10)`, `new LyricsParser(10)`, `new PgsParser(5)`, `new HtmlTranscriptParser(10)` | `new ReadOptions(lastCueDuration: 4)` and so on |
| `TmPlayerParser::DEFAULT_LAST_CUE_DURATION` and the same constant of 4 other parsers | `ReadOptions::$lastCueDuration`, 5 s for every format |
| `new WhisperJsonParser([WhisperJsonParser::OPTION_WORD_TIMESTAMPS => true])` | `new ReadOptions(format: new TranscriptReadOptions(wordTimestamps: true))`. The same for the YouTube, Podcasting 2.0 and cloud speech parsers |
| `new DeepgramParser([DeepgramParser::OPTION_SPEAKER_VOICES => true])` | `new ReadOptions(format: new TranscriptReadOptions(speakerVoices: true))`. The same for Whisper and the other cloud speech parsers |
| `new PodcastTranscriptParser([PodcastTranscriptParser::OPTION_KEEP_SEGMENTS => true], 10)` | `new ReadOptions(lastCueDuration: 10, format: new TranscriptReadOptions(keepSegments: true))` |
| `new CsvParser($columns, ';', 10)` | `new ReadOptions(lastCueDuration: 10, format: new CsvReadOptions($columns, ';'))` |
| `new CsvColumns(start: 'TC', frameRate: 25)` | `new CsvReadOptions(new CsvColumns(start: 'TC'), frameRate: 25)` |
| `CsvColumns::ROLES` | `@internal` |
| `new SccParser(2)` | `new ReadOptions(format: new SccReadOptions(channel: 2))` |
| `new EbuStlParser(true)` | `new ReadOptions(format: new EbuStlReadOptions(subtractStartOfProgramme: true))` |
| `new FfMetadataChaptersParser(3600)`, and the YouTube, Podcasting 2.0 and OGM chapter parsers | `new ReadOptions(format: new ChapterReadOptions(mediaDuration: 3600))` |

The per-format read classes and `CsvColumns` are in `SubtitleToolbox\Parsers\Options`. Each class name except `CsvColumns` ends in `ReadOptions`.

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
| `$subtitle->splitLongCues(new ResegmentOptions(maxCharactersPerLine: 42))` | `Resegmenter::apply($subtitle, new ResegmentOptions(mode: ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 42)))` |
| `$subtitle->resegmentByWords($options)` | `Resegmenter::apply($subtitle, new ResegmentOptions(mode: ResegmentMode::ByWords))` |
| `ReferenceSync::sync($german, $english, $options)->apply($german)` | `ReferenceSync::apply($german, new ReferenceSyncOptions(reference: $english))`. It returns the `ReferenceSyncReport`. The first parameter is `$subtitle`, not `$target` |
| `ShotChangeTiming::apply($subtitle, $shots, new ShotChangeOptions(24))` | `ShotChangeTiming::apply($subtitle, new ShotChangeOptions(frameRate: 24, shotChanges: $shots))` |
| `ShotChangeTiming::chainGaps($subtitle, $options)` | `ShotChangeTiming::apply($subtitle, $options)` without shot changes |
| `$fixes = CommonErrorFixer::fix($subtitle, $options)` | `$fixes = CommonErrorFixer::apply($subtitle, $options)->fixes`. `$options` is required |
| `$karaoke = WordHighlight::expand($subtitle, $options)` | `WordHighlight::apply($karaoke = clone $subtitle, $options)` |
| `$ranges = ProfanityFilter::apply($subtitle, $options)` | `$ranges = ProfanityFilter::apply($subtitle, $options)->muteRanges` |
| `SpeakerLabels::toPrefix($subtitle)` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::Prefix))` |
| `SpeakerLabels::toDialogueDashes($subtitle, '- ')` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::DialogueDashes, dialogueDashStyle: DialogueDashStyle::HyphenSpace))` |
| `SpeakerLabels::toColours($subtitle, $colors)` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::Colors, colors: $colors))` |
| `SpeakerLabels::fromPrefix($subtitle)` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(readPrefixes: true))` |
| `SpeakerLabels::rename($subtitle, ['MAN' => 'TOM'])` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(rename: ['MAN' => 'TOM']))` |
| `$subtitle->forcedOnly()` | `$subtitle->withForcedCuesOnly()` |
| `$subtitle->getErrors()` | `$subtitle->validate(ValidationRules::structure())`. The `cueIndex` of the result is null for a subtitle without cues |

`ResegmentOptions` and `ReferenceSyncOptions` have a new first parameter, and `ShotChangeOptions` has a new second one. `MergeShortCuesOptions` takes `limits`, `maxGap`, `minCharacters`, `keepSentenceEnds` and `mergeSameSpeakerAnyDuration`, in this order. `CueLimits` takes `minDuration` before `maxDuration`. Pass their arguments by name, as the table does.

`ReferenceSync` and `ReferenceSyncOptions` are in `SubtitleToolbox\Sync`. `ShotChangeTiming` and `ShotChangeOptions` are in `SubtitleToolbox\Timing`. The `HearingImpaired*` classes are in `SubtitleToolbox\HearingImpaired`, `Resegmenter` and the `Resegment*` classes in `SubtitleToolbox\Resegmenting`, and the `DualSubtitle*` classes in `SubtitleToolbox\Dual`.

## Subtitle and cues
A method that returns a new `Subtitle` starts with `with` or `to`. A method that changes the subtitle is a verb.

A lookup that starts with `get` returns a value or throws when nothing matches. A lookup that starts with `find` returns null or an empty array when nothing matches. A plain getter of a field, such as `getIdentifier()`, keeps `get`.

| 1.x | 2.0 |
|:--- |:--- |
| `$subtitle->slice(10, 20, true)` | `$subtitle->withSlice(10, 20, true)` |
| `$subtitle->filterCues(fn (SubtitleCue $cue) => $cue->isForced())` | `$subtitle->removeCuesWhere(fn (SubtitleCue $cue) => !$cue->isForced())`. The callback returns true for the cues to remove |
| `$subtitle->addCue($cue, false)` in a loop, then `reIndexCues()` | `$subtitle->addCues($cues)`. It adds all cues and sorts once. `addCue($cue)` sorts after each cue |
| `$subtitle->removeCue($index, false)` | `$subtitle->removeCue($index)`. It always numbers the cues from 0 again. Remove many cues with `removeCuesWhere()` |
| `$subtitle->changeCase('upper', 'tr')` | `$subtitle->changeCase(CaseMode::Upper, 'tr')`. The enum also has `Lower` and `Sentence` |
| `$cue->setLinesByArray(['Hi.', 'Bye.'])`, `$cue->setLinesByString("Hi.\nBye.")` | `$cue->setLines(['Hi.', 'Bye.'])`, `$cue->setLines("Hi.\nBye.")` |
| `$subtitle->getCuesAt(83.2)` | `$subtitle->findCuesAt(83.2)` |
| `$subtitle->getCueIndexAt(83.2)` | `$subtitle->findCueIndexAt(83.2)` |
| `$subtitle->getCuesBetween(600, 660)` | `$subtitle->findCuesBetween(600, 660)` |
| `$subtitle->getMetadata('title')` | `$subtitle->findMetadata('title')` |
| `$subtitle->getFormatData('ass')`, `$cue->getFormatData('ass')` | `$subtitle->findFormatData('ass')`, `$cue->findFormatData('ass')`. The parameter is `$key`, not `$format` |
| `setFormatData(format: 'ass', data: $data)` on `Subtitle` or `SubtitleCue` | `setFormatData(key: 'ass', data: $data)` |
| `$subtitle->findCues(fn: $callback)` | `$subtitle->findCues(predicate: $callback)`. `removeCuesWhere()` names its callback `$predicate` too |
| `$subtitle->replaceText('/x+/', 'y', true, false)` | `$subtitle->replaceText('/x+/', 'y', new ReplaceTextOptions(regex: true, caseSensitive: false))` |
| `$subtitle->getComments()[0]['text']`, `['beforeCueIndex']` | `$subtitle->getComments()[0]->text`, `->beforeCueIndex`. `getComments()` returns readonly `Comment` objects |
| `$subtitle->convertFrameRate(fromFps: 25, toFps: 23.976)` | `$subtitle->convertFrameRate(from: 25, to: 23.976)` |
| `$subtitle->wrapLines(maxCharsPerLine: 42)` | `$subtitle->wrapLines(maxCharactersPerLine: 42)` |
| `new MergeShortCuesOptions(maxCharactersPerLine: 37, maxGap: 0.5)` | `new MergeShortCuesOptions(limits: new CueLimits(maxCharactersPerLine: 37), maxGap: 0.5)`. `CueLimits` holds `maxCharactersPerLine`, `maxLinesPerCue`, `minDuration`, `maxDuration` and `maxCharactersPerSecond`. `ResegmentOptions` takes it too |
| `new MergeShortCuesOptions(maxLines: 1)` | `new MergeShortCuesOptions(limits: new CueLimits(maxLinesPerCue: 1))` |
| `$options->maxLines` of `MergeShortCuesOptions` or `ResegmentOptions`, and the same for `maxCharactersPerLine`, `minDuration`, `maxDuration` and `maxCharactersPerSecond` | `$options->limits->maxLinesPerCue`, `$options->limits->maxCharactersPerLine` and so on |
| `new MergeShortCuesOptions(sameSpeakerOnly: true)` | `new MergeShortCuesOptions(mergeSameSpeakerAnyDuration: true)`. It joins cues of the same speaker of any duration, as `sameSpeakerOnly` did |
| `(new FrameRate(25))->getFps()` | `(new FrameRate(25))->getFramesPerSecond()` |
| `new FrameRate(fps: 25)` | `new FrameRate(framesPerSecond: 25)` |
| `$warning->action === ParseWarning::SKIPPED`, `ParseWarning::REPAIRED` | `$warning->action === ParseWarningAction::Skipped`, `ParseWarningAction::Repaired` |
| `StringHelpers::UNIX_LINE_ENDING`, `WINDOWS_LINE_ENDING`, `MAC_LINE_ENDING` | `LineEnding::Lf->value`, `LineEnding::Crlf->value`, `"\r"` |
| `StringHelpers` methods other than `convertToUtf8()` and `isValidUtf8()` | `@internal` |
| `Markup::CORE_TAGS`, `WORD_TIMESTAMP_REGEX`, `unescapeText()`, `escapeTextLike()`, `splitTags()`, `plainLines()`, `countCharacters()`, `characters()`, `words()`, `toSingleLine()`, `openCoreTags()`, `closeCoreTags()`, `coreTimestamp()` | `@internal`. [markup.md](markup.md) lists the public members |
| `Cea608`, `CodePage`, `Iso6937`, `EbmlReader`, `PaletteReducer`, `ImageFormatter` and the traits of `Subtitle` | `@internal` |
| `SccParser::HEADER`, `MODE_POP_ON`, `MODE_ROLL_UP`, `MODE_PAINT_ON`, `SubViewerParser::START_SCRIPT`, `METADATA_TAGS`, `CsvParser::DELIMITERS`, `LyricsParser::METADATA_TAGS`, `FfMetadataChaptersParser::METADATA_KEYS`, `WebVttParser::REGION_SETTINGS`, `WebVttParser::CUE_SETTINGS`, `CsvParser::checkDelimiter()` | `@internal`. The SCC format data keeps the values `pop-on`, `roll-up` and `paint-on` |
| a class that extends `Subtitle`, `SubtitleCue`, `FrameRate`, `Markup`, `SubtitleStatistics`, a stream writer or an exception class | every concrete class is `final`, except `InvalidParserException` and `InvalidArgumentException`. Wrap the class in your own class |
| `new ValidationRules(noIndexGaps: true)`, `ValidationResult::RULE_INDEX_GAP` | removed. Cue indexes have no gaps, because `removeCue()` always numbers the cues from 0 again |

## Services and reports
Each service result is a `*Report` with `public readonly` fields, or a value object with `public readonly` fields. String constant sets are backed enums. The value of each case is the 1.x string.

Only the library creates the reports and results. Their constructors are `@internal`: `CommonErrorReport`, `HearingImpairedReport`, `OcrReport`, `ProfanityReport`, `ReferenceSyncReport`, `ResegmentReport`, `ShotChangeReport`, `SpeakerLabelReport`, `TranslationReport`, `WordHighlightReport`, `AppliedFix`, `CueDifference`, `ValidationViolation`, `TranslationWarning`, `MuteRange`, `MatroskaTrack`, `HlsWebVttRendition`, and `ParseWarning` with `ParseWarning::skipped()`. `RecognizedText` and `Comment` keep public constructors.

| 1.x | 2.0 |
|:--- |:--- |
| `SubtitleToolbox\HearingImpairedRemover`, `HearingImpairedOptions`, `HearingImpairedReport` | `SubtitleToolbox\HearingImpaired\HearingImpairedRemover` and the same for the other 2 |
| `SubtitleToolbox\ResegmentOptions`, `ResegmentMode`, `ResegmentReport` | `SubtitleToolbox\Resegmenting\ResegmentOptions` and the same for the other 2 |
| `DualSubtitle::merge($english, $german, $options)` | `SubtitleToolbox\Dual\DualSubtitle::fromPair($english, $german, $options)` |
| `DualSubtitleOptions::MODE_STACK`, `MODE_TOP_BOTTOM` | `DualSubtitleMode::Stack`, `DualSubtitleMode::TopBottom` |
| `$result = ReferenceSync::sync(...)` with `$result->getOffset()`, `getScale()`, `getScore()` | `$report = ReferenceSync::apply(...)` with the `ReferenceSyncReport` fields `offset`, `scale` and `score`. `getSegments()` stays |
| `$difference->getKind() === CueDifference::KIND_TEXT_CHANGED` | `$difference->kind === CueDifferenceKind::TextChanged`. `getOldIndex()`, `getNewIndex()`, `getOldCue()` and `getNewCue()` become the fields `oldIndex`, `newIndex`, `oldCue` and `newCue` |
| `ValidationResult` with `getCueIndex()`, `getRule()`, `getValue()` and `getLimit()` | `ValidationViolation` with the fields `cueIndex`, `rule`, `value` and `limit` |
| `ValidationResult::RULE_MAX_CHARACTERS_PER_LINE` and the other `RULE_*` constants | `ValidationRule::MaxCharactersPerLine`. Each case is the field name of `ValidationRules`: `RULE_OVERLAP` becomes `NoOverlap`, `RULE_EMPTY_CUE` becomes `NoEmptyCues`, `RULE_UNSORTED_CUES` becomes `NoUnsortedCues`, `RULE_NEGATIVE_DURATION` becomes `NoNegativeDuration` |
| `ValidationRules::netflixEnglish(fps: 24)` | `ValidationRules::netflixEnglish(frameRate: 24)` |
| `YouTubeChapters::check()` returns `['rule' => YouTubeChapters::RULE_MIN_DURATION, 'chapterIndex' => 2, ...]` | it returns `ValidationViolation` objects. `RULE_FIRST_CHAPTER_AT_ZERO`, `RULE_MIN_CHAPTERS` and `RULE_MIN_DURATION` become `ValidationRule::FirstChapterAtZero`, `MinChapters` and `MinDuration`. `chapterIndex` becomes `cueIndex` |
| `CommonErrorFixer::RULES`, `AppliedFix::$rule` as a string | `CommonErrorRule::cases()` in run order, `AppliedFix::$rule` as a `CommonErrorRule` |
| `new CommonErrorOptions(dialogueDash: '-')` | `new CommonErrorOptions(dialogueDashStyle: DialogueDashStyle::Hyphen)`. `DialogueDashStyle` has a case for a hyphen, U+2010, an en dash and an em dash, each with and without a space |
| `new ValidationRules(dialogueDashStyle: "\u{2013} ")` | `new ValidationRules(dialogueDashStyle: DialogueDashStyle::EnDashSpace)` |
| `CommonErrorFixer::apply($subtitle, new CommonErrorOptions(dryRun: true))` | `CommonErrorFixer::preview($subtitle, new CommonErrorOptions())` |
| `WordHighlightOptions::MODE_WORD`, `MODE_CUMULATIVE` | `WordHighlightMode::Word`, `WordHighlightMode::Cumulative` |
| `ProfanityOptions::MASK_STARS`, `MASK_FIRST_LETTER`, `MASK_REMOVE`, `MASK_NONE` | `ProfanityMask::Stars`, `FirstLetter`, `Remove`, `None` |
| `new ProfanityOptions(wordFile: 'words.txt')` | `new ProfanityOptions($words)`, with the list of words. The CLI `--mask-words` still reads a file |
| `OcrEngineChooser::ENGINE_TESSERACT`, `ENGINE_GLYPH`, `ENGINES`, and `choose()` returns a string | `OcrEngineName::Tesseract`, `OcrEngineName::Glyph`, `OcrEngineName::cases()`. `choose()` and `create()` take and return an `OcrEngineName` |
| `OcrResult` | `RecognizedText` |
| `$results = (new OcrRunner($engine))->run($subtitle)` | `$results = (new OcrRunner($engine))->run($subtitle)->texts`. `run()` returns an `OcrReport` |
| `GlyphOcrEngine::toOcrResult()`, `TesseractOcrEngine::fromTsv()` | `@internal` |
| `new TesseractOcrEngine('deu+eng', 6, 'tesseract', 2.0, true, 128)` | `new TesseractOcrEngine(new TesseractOcrOptions(language: 'deu+eng', pageSegmentationMode: 6, program: 'tesseract', scale: 2.0, invert: true, threshold: 128))`. The constructor takes one `TesseractOcrOptions`, as `GlyphOcrEngine` takes `GlyphOcrOptions` |
| `new GlyphOcrEngine($database, ['italicSlant' => 0.2, 'lineContext' => false])` | `new GlyphOcrEngine(new GlyphOcrOptions(database: $database, italicSlant: 0.2, lineContext: false))`. `GlyphOcrOptions` has one typed field per `GlyphOcr\Recognizer` setting and checks the values. A misspelled name is a PHP `Error` |
| `DualSubtitleOptions::getSecondaryTagName()`, `WordHighlightOptions::getTagName()`, the `Parsers\WordGrouping` trait | `@internal` |
| `HlsWebVttResult`, `HlsWebVttResult::segmentMillis()` | `HlsWebVttRendition`. `segmentMillis()` is gone |
| `$copy = $runner->translate($german, 'de', 'en')`, then `$runner->getWarnings()` | `$report = $runner->translate($copy = clone $german, 'de', 'en')`, then `$report->warnings`. `translate()` changes the subtitle you pass and keeps no state |
| `$runner->translate($german, source: 'de', target: 'en')` | `$runner->translate($german, sourceLanguage: 'de', targetLanguage: 'en')`, the names of `TranslationEngine::translate()` |
| `SpeakerLabels::BBC_COLOURS` | `SpeakerLabels::BBC_COLORS` |
| `SpeakerLabels::fromPrefix($subtitle, false)` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(readPrefixes: true, readUpperCaseOnly: false))` |
| `SpeakerLabels::toPrefix($subtitle, false, ' - ')` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::Prefix, writeUpperCase: false, separator: ' - '))` |
| `new ShotChangeOptions(24, snapWindow: 12, minDuration: 20)` | `new ShotChangeOptions(24, snapWindowFrames: 12, minDurationFrames: 20)` |
| `$stats->getCueCount()`, `getWordCount()`, `getCharacterCount()`, `getTotalDisplayTime()`, `getSpan()` | the `SubtitleStatistics` fields `cueCount`, `wordCount`, `characterCount`, `totalDisplayTime` and `span` |
| `$stats->getCharactersPerSecond()`, `getWordsPerMinute()`, `getCharactersPerLine()` | the fields `charactersPerSecond`, `wordsPerMinute` and `charactersPerLine` |
| `$stats->getGap()`, `toArray()['gap']` | the field `gaps`, `toArray()['gaps']` |
| `$stats->getMostUsedWords(10)` returns `['you' => 211]` | `array_slice($stats->mostUsedWords, 0, 10)` returns `[['word' => 'you', 'count' => 211]]`. The field `mostUsedWords` holds every word, the most used first. A word such as `2024` stays a string |

## Exceptions
| 1.x | 2.0 |
|:--- |:--- |
| `$exception->getErrorCode()` | `$exception->getCode()` |
| `new ParsingException($message, $lineNumber)` | the same, plus an optional third argument `$previous`. The other library exceptions take `($message, $previous)` |
| `catch (GenericException $e)` | `catch (SubtitleToolboxException $e)`. `GenericException` is `@internal` |
| `catch (InvalidArgumentException $e)` around `recognizeText()` or `OcrRunner::run()` | `catch (OcrException $e)` for a failed OCR run on an image, error code 107. A missing engine or language still throws `InvalidArgumentException` |

## Command line tool
See [cli.md](cli.md) for every command and option.

| 1.x | 2.0 |
|:--- |:--- |
| `--from ytchapter`, `--to podcast`, and the same for `ogm` and `ffmeta` | still works. The new names are `youtube-chapters`, `podcast-chapters`, `ogm-chapters` and `ffmeta-chapters`. `formats`, `info` and the `format` field of `validate --json` print the new names |
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
| `convert --speakers colours` | `convert --speakers colors` |
| `info --json` or `validate --json` with one input printed one object. `diff --json` always printed one object | they always print a list, with one object for each input, or one object for the pair of files of `diff`. Read `[0]` for one input |
| `info --json`, `validate --json` or `diff --json` printed nothing when every file failed | they print `[]` |
| `validate --json` with `results`, each with `cueIndex` and `cueNumber` | `violations`, each with `cueIndex` only. `cueIndex` starts at 0, so the cue number is `cueIndex + 1` |
| `diff --json` with `old` and `new` for the file names | `oldFile` and `newFile`. Each difference keeps `old` and `new` for the cues |
| only `info --json` had `warnings` | `validate --json` has `warnings` too, and `diff --json` has `oldWarnings` and `newWarnings`. `diff`, `dual` and `sync --reference` also print the warnings of their second file to standard error |
| the classes in `SubtitleToolbox\Cli`, for example a subclass of `InfoCommand` | `@internal`, and every class that is not abstract is final. Run the binary. Only its commands, options, exit codes and `--json` shapes are stable |

### Removed commands
2.0 removes the commands `shift`, `scale`, `fps` with its alias `sync-fps`, `fix`, `strip-sdh` and `snap`. They fail like any unknown command: exit code 2 and a pointer to the command list. Use the `retime` or `convert` call of the table.

| 1.x | 2.0 |
|:--- |:--- |
| `shift FILE --by 2 --after 60` | `retime FILE --shift 2 --shift-after 60` |
| `scale FILE --factor 1.001` | `retime FILE --scale 1.001` |
| `fps FILE --from 25 --to 23.976`, `sync-fps FILE --from 25 --to 23.976` | `retime FILE --from-fps 25 --to-fps 23.976` |
| `fix FILE --overlaps --min-duration 1 --min-gap 0.083` | `convert FILE --timing-fix-overlaps --timing-min-duration 1 --timing-min-gap 0.083` |
| `fix FILE --common-errors --replace-list L --list-fixes --language de` | `convert FILE --errors-fix --errors-replace-list L --errors-list --language de` |
| `fix FILE --wrap 32 --max-lines 3` | `convert FILE --structure-wrap --structure-max-cpl 32 --structure-max-lines 3`. `--structure-wrap` takes no value. Its width is `--structure-max-cpl`, default 42 |
| `fix FILE --resegment --max-word-gap 0.3 --max-cpl 32 --max-lines 1` | `convert FILE --structure-resegment --structure-max-word-gap 0.3 --structure-max-cpl 32 --structure-max-lines 1` |
| `fix FILE --unwrap`, `--merge-short`, `--split-long`, `--merge-duplicates` | `convert FILE --structure-unwrap`, `--structure-merge-short`, `--structure-split-long`, `--structure-merge-duplicates` |
| `strip-sdh FILE` | `convert FILE --sdh` |
| `strip-sdh FILE --lyrics --brackets "{}"` | `convert FILE --sdh --sdh-lyrics --sdh-brackets "{}"`. Each `strip-sdh --X` option becomes `--sdh-X` |
| `snap FILE --shot-changes F --fps 24` | `convert FILE --snap-shot-changes F --video-fps 24` |
| `snap FILE --shot-changes F --fps 24 --snap-window 12 --min-gap-frames 2 --min-duration-frames 20 --no-chain` | `convert FILE --snap-shot-changes F --video-fps 24 --snap-window-frames 12 --snap-min-gap-frames 2 --snap-min-duration-frames 20 --snap-no-chain` |
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
| CLI output names | with `--force`, the second of two inputs with the same output file overwrote the first output. `--mute-edl`, `--mute-filter` and the `hls` files could overwrite an input or each other | the command fails with exit code 2 before it writes a file. Standard input with `--output-dir` fails too | pass such inputs in two runs, and give each output its own name |
| Unknown options | before 1.70.5, a misspelled key or a key of another format was ignored. 1.70.5 and later threw `InvalidArgumentException` | a misspelled field, such as `new WriteOptions(lineEndings: LineEnding::Crlf)`, is a PHP `Error` for an unknown named parameter. An options class of another format, such as `new CsvWriteOptions()` for SubRip output, throws `InvalidArgumentException`. Read classes follow the same rule | fix the name, or pass the class of the format |
| Strict types | the library converted scalar values | every file declares `strict_types`. A `mapText()`, `mapLines()`, `Markup::mapTextRuns()` or `ProfanityOptions` mask callback must return a string, else it throws `TypeError`. | return the documented type |
| CSV and TSV times in `hh:mm:ss:ff` | the CLI could not read such a file | the CLI `--input-fps` and `--fps` set `CsvReadOptions::$frameRate` | nothing |
| JSON output of text that is not UTF-8 | `JsonFormatter` and the Podcasting 2.0 formatters threw `JsonException` | they throw `UnwritableContentException`, error code 108, with the `JsonException` as its previous exception | catch `UnwritableContentException`, `InvalidArgumentException` or `SubtitleToolboxException` |
| `ParseWarning::$lineNumber`, `$blockIndex` | 0 for a warning without a line, -1 for a library JSON field outside the cues | null in both cases | test for null |
| `ParseWarning::$message` | ended with " (line N)" for MicroDVD, MPSub, MPL2, TMPlayer, ASS, SubViewer, CSV, YouTube XML and HTML | holds no line suffix. `$lineNumber` holds the line | read `$lineNumber` |
| Word timestamps | `shift()`, `scale()`, `convertFrameRate()`, `syncByTwoPoints()`, `merge()` with an offset, `slice()` with `$moveToZero`, `ReferenceSync` and the CLI `retime` and `sync` kept the word timestamps in the cue text, such as `<00:00:02.000>`, at their old times | they move the word timestamps with the cues. Mute ranges of the profanity filter and the WebVTT, LRC and ASS karaoke output use the moved times | nothing. The 1.x times were wrong |
| Library JSON format data | `JsonParser` and `fromArray()` took any value in the format data. A formatter then failed with a PHP `TypeError` or `Error` | they throw `ParsingException` with the path of a format data field of the wrong type, for example `formatData.scc.dropFrame`. A lenient `JsonParser` drops the bad format data of that format, or skips the cue | fix the field |
| Wrong JSON types in the speech-to-text and Podcasting 2.0 parsers | a Whisper `segments` object, a Google `alternatives` string, or a time such as `1e400` gave a PHP `TypeError` or an infinite time | they throw `ParsingException` with the path of the field, or skip the block in lenient mode. A Podcasting 2.0 chapter `endTime` that is not a number throws, where 1.x ignored it | fix the field |
| CLI errors | a PHP `Error` such as `TypeError` stopped the tool with a PHP fatal error | the tool prints `FILE: TypeError: MESSAGE`, fails that file with exit code 3 and goes on with `--keep-going` | nothing |
| CLI exit codes | 1 for a file that failed, a broken `validate` rule and a `diff` difference | 1 only for a broken rule and a difference. 3 for a file that could not be read or written, also a file of `--mask-words`, `--errors-replace-list`, `--snap-shot-changes` or `--ocr-database`. `--ass-karaoke-tag` with another output format than ASS, and `validate --video-fps` without `--preset netflix-en`, give 2 before the tool reads a file | test for 3 where a script tested for a failed file with 1 |
| HLS segments | `HlsWebVttResult::getSegments()` and `getDurations()` returned arrays | they return generators that write each segment when you read it, so a long subtitle needs no memory for all segments. `getSegmentCount()` returns the number | `iterator_to_array($hls->getSegments())` |
| Sync limits | `ReferenceSyncOptions` and the CLI `sync` took any offset range and any number of splits, and ran out of memory on large values | `minOffset` and `maxOffset` are from -86,400 to 86,400 s and at most 7,200 s apart. `maxSplits` is from 0 to 10. A larger value throws `InvalidArgumentException`, and the CLI exits with code 2 | keep the values in these ranges |
| Image size | the PGS and VobSub parsers decoded an image of any size | an image larger than 7,680 pixels per side or 8,294,400 pixels, one 3840x2160 frame, throws `ParsingException`. `new CueImage()` and `PngDecoder::decode()` throw `InvalidArgumentException` | nothing for Blu-ray, UHD and DVD files, whose images are at most 3840x2160 |
| Stored TTML head that is not valid XML | `toString(Format::Ttml)` threw `InvalidFormatterException`, error code 101 | it throws `UnwritableContentException`, error code 108 | catch `UnwritableContentException` or `InvalidArgumentException` |
| Content that the output format cannot hold | SCC with more than 4 lines or 32 characters per line, PGS with a text cue, and EBU STL with a subtitle number over 65535 threw `InvalidArgumentException`, error code 104 | they throw `UnwritableContentException`, error code 108. It extends `InvalidArgumentException` | nothing, or test for code 108 |
| Karaoke | `WordHighlight::expand()` returned a new subtitle and left its input as it was | `WordHighlight::apply()` changes the subtitle that you pass | pass `clone $subtitle` |
| Translation | `TranslationRunner::translate()` returned a translated copy | it translates the subtitle that you pass, after the last engine call succeeds | pass `clone $subtitle` |
| OCR failures | a failed Tesseract run or a php-glyph-ocr error on an image threw `InvalidArgumentException`, error code 104 | they throw `OcrException`, error code 107. A missing `tesseract` program, Tesseract language or php-glyph-ocr package still throws `InvalidArgumentException` | catch `OcrException` or `SubtitleToolboxException` |
| php-glyph-ocr version | Composer installed any version of `yama6a/php-glyph-ocr` next to the library | Composer refuses a version below 0.3, and 0.4 or later | `composer require yama6a/php-glyph-ocr:^0.3` |
| CLI `--ocr-language` without installed data | the file failed with exit code 1 | the tool stops before the first file with exit code 2 | install the language |
| `setFormatData()` of `Subtitle` and `SubtitleCue` | stored any array. A formatter then failed with a PHP `TypeError` or `Error` | a field that a formatter reads, such as `scc.dropFrame`, must have its type. A wrong type throws `InvalidArgumentException` with the path of the field | fix the field |
| `SubtitleCue::setLines()` with a value that is no string or array | threw `InvalidArgumentException` | throws a PHP `TypeError` | pass a string or a list of strings |
| `SubtitleStatistics` without data | the ranges and the span were 0, for example a gap of `['min' => 0, ...]` for 1 cue | `span` is null without cues. `charactersPerSecond`, `wordsPerMinute`, `charactersPerLine` and `gaps` are null when there is no value to measure, for example `gaps` with fewer than 2 cues. `toArray()` and CLI `info --json` write null. CLI `info` prints `-` | test for null |
| CLI `info --json` | `statistics.gap`, and `statistics.mostUsedWords` as an object of word and count | `statistics.gaps`, and `statistics.mostUsedWords` as a list of `{"word": ..., "count": ...}` | read the new keys |
| CLI `info` text output | the line `Gap:` | the line `Gaps:` | read the new label |

Code that does not declare `strict_types` itself still calls the library as before. `new SubtitleCue("1", 2)` from such a file works.
