# Cue text

## Text runs
Most methods on this page see only the **text runs** of a cue: the text between tags, with `&lt;`, `&gt;` and `&amp;` decoded. So a search for `&` finds `&amp;`, and a search for `font` finds no markup. The result gets escaped again, so a replacement cannot add tags. A match cannot cross a tag: `Colour` does not match `<i>Col</i>our`.

## Transforms
```php
use SubtitleToolbox\SubtitleCue;

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

- **Text runs**: `replaceText()`, `changeCase()` and `mapText()` work on text runs. Use `mapLines()` to change tags.
- **Word timestamps**: `stripFormatting()` keeps them. Pass `false` as the second argument to remove them too.
- **Empty cues**: a transform removes a cue that had text before and has only tags or spaces after. Comments stay before the next cue.
- **Case modes**: `upper`, `lower` and `sentence`.
- **Unicode**: with `ext-mbstring`, the full Unicode case mapping applies. `ß` becomes `SS`, and Greek `Σ` at the end of a word becomes `ς` in lower case. Without `ext-mbstring`, or for text that is not valid UTF-8, only the letters A to Z change.
- **Turkish and Azerbaijani**: pass `'tr'` or `'az'` as the second argument of `changeCase()`. Then `i` and `İ` pair, and `ı` and `I` pair. Without it, `İ` becomes `i` with a combining dot, U+0307.
- **Sentence case**: a sentence starts at the start of a cue, and at the first letter or digit after `.`, `!` or `?` and a space or line break. `www.example.com` stays lower case. Names and the English word `I` become lower case. Fix them after with `replaceText()`.

## Hearing-impaired annotations
```php
use SubtitleToolbox\HearingImpairedOptions;
use SubtitleToolbox\HearingImpairedRemover;

$report = HearingImpairedRemover::apply($subtitle, new HearingImpairedOptions());   // '(laughs) You came back.' becomes 'You came back.'
$report->removedLines;                      // the lines that went, the lines of removed cues included
$report->removedCues;                       // the cues that had text and have none left

HearingImpairedRemover::apply($subtitle, new HearingImpairedOptions(
    speakerLabelsUpperCaseOnly: false,      // also removes 'Baker:' and 'Note:'
    customBrackets: [['{', '}'], ['*', '*']],
    lyrics: true,                           // removes '# The wheels go round #'
));
HearingImpairedRemover::isAnnotation('JOHN: Hi.', new HearingImpairedOptions());   // true, apply() would change the line
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

- **Rules**: they follow the "Remove text for hearing impaired" tool of [Subtitle Edit](https://github.com/SubtitleEdit/subtitleedit). Its interjection list and its "only separate lines" options are not available.
- **Tags**: the rules see text runs, but a bracket can span tags and lines. Tags stay, and a tag pair that becomes empty, such as `<i></i>`, goes.
- **Spaces**: the space next to a removed annotation goes too. `Wait (sighs) now.` becomes `Wait now.`
- **Empty lines and cues**: a line with only a dash left goes. A cue with no text left goes, and comments stay before the next cue.
- **Dialogue dashes**: when only one of two or more dash lines stays, its `- ` goes too. `- Is it open?` and `- (laughs)` become `Is it open?`.

## Speakers
Core markup holds a speaker as `<v Anna>`. `SpeakerLabels` converts it to the forms that formats without `<v>` can show, and back.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Speakers\SpeakerLabelOptions;
use SubtitleToolbox\Speakers\SpeakerLabels;
use SubtitleToolbox\Speakers\SpeakerStyle;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString($whisperXJson, Format::Whisper, new ReadOptions(speakerVoices: true));
SpeakerLabels::list($subtitle);                    // ['SPEAKER_00' => 14, 'SPEAKER_01' => 9], cues per speaker
$report = SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(
    rename: ['SPEAKER_00' => 'Anna', 'SPEAKER_01' => 'Ben'],
    to: SpeakerStyle::Prefix,                      // '<v Anna>Where were you?' becomes 'ANNA: Where were you?'
));
$report->changedCues;                              // the cues whose lines changed
$subtitle->toString(Format::SubRip);
```

`apply()` runs the steps that the options ask for, in this order: `from`, `rename`, `to`.

| Option | Input | Output |
|:--- |:--- |:--- |
| `from: SpeakerStyle::Prefix`, with `upperCaseOnly`, default `true` | `JOHN: Hi.` | `<v John>Hi.` |
| `rename: ['SPEAKER_00' => 'Anna']` | `<v SPEAKER_00>` | `<v Anna>` |
| `to: SpeakerStyle::Prefix`, with `upperCase`, default `true`, and `separator`, default `': '` | `<v Anna>Where were you?` | `ANNA: Where were you?` |
| `to: SpeakerStyle::DialogueDashes`, with `dash`, default `'- '` | `<v Anna>Where?` and `<v Ben>Home.` in one cue | `- Where?` and `- Home.` |
| `to: SpeakerStyle::Colours`, with `colours`, default `SpeakerLabels::BBC_COLOURS` | `<v Anna>Where?` and `<v Ben>Home.` | `<font color="#ffffff">Where?</font>` and `<font color="#ffff00">Home.</font>` |

| Format | Reads `<v>` from | Writes `<v>` as |
|:--- |:--- |:--- |
| WebVTT | `<v Anna>` | `<v Anna>` |
| TTML, IMSC, DFXP | `ttm:agent` with its `ttm:name` | `ttm:agent` |
| ASS, SSA | the Name field | the Name field, only the first speaker of a cue |
| CSV, TSV | the `speaker` column | the `speaker` column |
| Podcasting 2.0 transcript JSON | the segment `speaker` | the segment `speaker` |
| HTML transcript | `<cite>` | `<cite>` |
| Whisper JSON | the segment `speaker`, with `ReadOptions::$speakerVoices` | no formatter |
| Cloud speech-to-text JSON | the speaker labels of the service, with `ReadOptions::$speakerVoices` | no formatter |
| JSON | the cue lines | the cue lines |
| all other formats, iTT too | no speaker | nothing. Convert with `to: SpeakerStyle::Prefix`, `DialogueDashes` or `Colours` first |

- **Speaker**: a `<v>` tag sets the speaker until `</v>`, the next `<v>` tag or the end of the cue.
- **New line**: where the speaker changes in the middle of a line, the converters start a new line. Style tags such as `<i>` close at the end of the first line and open again on the next.
- **Prefix**: every cue repeats the name of its speaker. `upperCase: false` keeps the name as it is.
- **Dashes**: only cues with two or more speakers get dashes. Text without a speaker counts as one speaker. A line that already starts with `-` gets no second dash.
- **Colours**: the BBC order is white, yellow, cyan and green, from the [BBC Subtitle Guidelines](https://www.bbc.co.uk/accessibility/forproducts/guides/subtitles/). Each speaker gets the next colour in the order of its first cue. The fifth speaker gets the first colour again. A colour that is not `#rrggbb` throws `InvalidArgumentException`.
- **From**: `from` takes only `SpeakerStyle::Prefix`. Another style throws `InvalidArgumentException`.
- **Labels**: `from: SpeakerStyle::Prefix` uses the `speakerLabels` rule of `HearingImpairedOptions`. With `upperCaseOnly: false`, it also reads `Baker:` and `Note:`.
- **Label names**: an upper case label becomes title case, so `DR. O'NEIL:` becomes `<v Dr. O'Neil>`. The dash before a label goes. A label on a line of its own names the speaker of the next line.
- **Whisper**: the `speaker` field also stays in the cue format data. whisper.cpp `-di` writes the speakers `0` and `1`, and `?` when it cannot tell. The parser ignores the speaker of each WhisperX word.
- **Names**: the `list()` key of a speaker such as `0` is an int. A quote in a name stays a raw character, see [markup.md](markup.md).

## Profanity filter
`ProfanityFilter` masks words in the cue text. Its report holds the time ranges of the matches, so that a video player or FFmpeg can mute the audio there.

```php
use SubtitleToolbox\Profanity\MuteRange;
use SubtitleToolbox\Profanity\ProfanityFilter;
use SubtitleToolbox\Profanity\ProfanityOptions;

// 00:01:02.000 --> 00:01:04.000
// <00:01:02.000>What <00:01:02.300>the <00:01:02.480>hell <00:01:02.800>is this?
$ranges = ProfanityFilter::apply($subtitle, new ProfanityOptions(
    words: ['hell', 'damn*'],                    // * at the end matches any ending, so "damned" matches
    mask: ProfanityOptions::MASK_FIRST_LETTER,
    padding: 0.1,                                // seconds added on both sides of a range
))->muteRanges;
// cue text: "<00:01:02.000>What <00:01:02.300>the <00:01:02.480>h*** <00:01:02.800>is this?"
$ranges[0]->start;                               // 62.38
$ranges[0]->end;                                 // 62.9

file_put_contents('movie.edl', MuteRange::toEdl($ranges));     // "62.380 62.900 1\n"
MuteRange::toFfmpegVolumeFilter($ranges);                       // "volume=enable='between(t,62.380,62.900)':volume=0"

new ProfanityOptions(wordFile: 'words-en.txt');                 // one word per line
new ProfanityOptions(['hell'], fn (string $word): string => '[beep]');
```

| Mask | `What the hell?` becomes |
|:--- |:--- |
| `MASK_STARS` (default) | `What the ****?` |
| `MASK_FIRST_LETTER` | `What the h***?` |
| `MASK_REMOVE` | `What the ?` |
| `MASK_NONE` | `What the hell?`. Only the report has the ranges |
| a callback | the string the callback returns for the matched word |

- **No word list**: the package ships none. The words to filter depend on the language and the audience.
- **Matches**: case-insensitive and Unicode-aware. A match is a whole word, so `hell` does not match `hello` or `shell`. A word can hold spaces, such as `son of a`. A `*` in another place than the end throws `InvalidArgumentException`.
- **Word file**: one word per line. The filter ignores a UTF-8 BOM, CR LF line endings and empty lines. The words of `wordFile` add to the words of `words`.
- **Range**: the range runs from the word timestamp before the match to the next word timestamp. Without a timestamp on a side, the range uses the start or end of the cue. Padding then widens the range. A range does not start before 0.
- **Joining**: `muteRanges` is sorted by time. Ranges that touch or overlap after the padding become one range.
- **Removed cues**: `MASK_REMOVE` removes a cue that has no visible text left, and re-indexes the cues.
- **Text runs**: the filter sees text runs. It does not find a word that a tag splits, such as `h<i>ell</i>`, or a word across two lines.
- **EDL**: `toEdl()` writes the [Kodi](https://kodi.wiki/view/Edit_decision_list) and MPlayer format. Each line holds the start, the end and action `1`, mute.
- **FFmpeg**: use the filter as `ffmpeg -i in.mp4 -af "<filter>" -c:v copy out.mp4`. It returns `""` for no ranges. Then leave out `-af`.

## Word highlight and karaoke
Lyric videos and short-form captions show a line and mark the word that is sung or spoken. SubRip and WebVTT players have no karaoke effect. So `WordHighlight` writes one cue per word, with the active word styled. The word timestamps come from Whisper JSON with `ReadOptions::$wordTimestamps`, enhanced LRC, or ASS `\k` tags.

```php
use SubtitleToolbox\Karaoke\WordHighlight;
use SubtitleToolbox\Karaoke\WordHighlightOptions;

// 00:00:00.000 --> 00:00:01.600  <00:00:00.000>The <00:00:00.240>beach <00:00:00.710>was <00:00:00.950>quiet.
$karaoke = clone $subtitle;
$report  = WordHighlight::apply($karaoke, new WordHighlightOptions(style: 'u'));
$report->cuesAfter;                                    // 4
// 00:00:00.000 --> 00:00:00.240  <u>The</u> beach was quiet.
// 00:00:00.240 --> 00:00:00.710  The <u>beach</u> was quiet.
// ...

WordHighlight::apply($subtitle, new WordHighlightOptions(
    style: 'font color="#ffff00"',                     // b, i, u (default), s or font
    mode: WordHighlightOptions::MODE_CUMULATIVE,       // styles all words up to the active one
    maxWordsPerCue: 1,                                 // shows only the active word
));
```

| Mode | Cue at `was` |
|:--- |:--- |
| `word`, the default | `The beach <u>was</u> quiet.` |
| `cumulative` | `<u>The beach was</u> quiet.` |
| `word` with `maxWordsPerCue: 3` | `beach <u>was</u> quiet.` |

- **Result**: `apply()` replaces the cues of the subtitle with word cues without word timestamps. Pass `clone $subtitle` to keep the original. A cue without word timestamps stays as it is. Comments stay before the first word cue of their cue.
- **Times**: a word cue lasts from its timestamp to the next one. The last word lasts until the cue end. The time before the first timestamp gets a cue without a styled word. A word of 0 s gets no cue.
- **Cue data**: each word cue keeps the alignment, the forced flag and the format data. Only the first word cue keeps the identifier.
- **Window**: `maxWordsPerCue` shows the active word in the middle of N words. At the start and the end of a cue, the window stops at the first or last word.
- **Markup**: the style wraps the text of each word in logical order, so right-to-left text such as Hebrew works. The style closes before another tag and opens again after it, for example `<i><u>train</u></i>`.
- **ASS karaoke**: to keep the timing as ASS karaoke tags in place of one cue per word, write ASS directly. See [formats.md](formats.md#ass-and-ssa) for `AssWriteOptions::$karaokeTag`.

## Fixing common errors
OCR of PGS and VobSub cues reads `It's` as `lt's`. Files from the web have spaces before `?` and tags that never close. `CommonErrorFixer` fixes such errors in one call and lists each change for review.

```php
use SubtitleToolbox\Fixing\CommonErrorFixer;
use SubtitleToolbox\Fixing\CommonErrorOptions;
use SubtitleToolbox\Fixing\OcrReplaceList;

$fixes = CommonErrorFixer::apply($subtitle, new CommonErrorOptions(language: 'en'))->fixes;
$fixes[0]->cueIndex;   // 14
$fixes[0]->rule;       // 'ocrLowercaseL'
$fixes[0]->before;     // "lt's late."
$fixes[0]->after;      // "It's late."

CommonErrorFixer::apply($subtitle, new CommonErrorOptions(
    language: 'fr',
    dialogueDash: '-',                                       // '- ' (default), '-', or an en or em dash with or without a space
    unicodeEllipsis: true,                                   // writes U+2026 for every ellipsis
    replaceList: OcrReplaceList::fromSubtitleEditXml(file_get_contents('fra_OCRFixReplaceList_User.xml')),
    dryRun: true,                                            // lists the fixes and changes nothing
));
```

| Option | Before | After |
|:--- |:--- |:--- |
| `doubleSpaces` | `Hi <i> there</i>` | `Hi <i>there</i>` |
| `spaceBeforePunctuation` | `Really ?` | `Really?`. French keeps the space before `?`, `!`, `:` and `;` |
| `missingSpaceAfterPunctuation` | `Stop.Now`, `Hi!How` | `Stop. Now`, `Hi! How`. Not in `1.5`, `www.example.com`, `e.g.` or `U.S.Army` |
| `unbalancedTags` | `<i>Hello` | `<i>Hello</i>` |
| `emptyTags` | `Hi <i></i>there` | `Hi there` |
| `dialogueDashes` | `-Hi.` and `-Hello.` | `- Hi.` and `- Hello.`, or the style of `dialogueDash` |
| `ellipsis` | `. . .` or `....` | `...`, or U+2026 with `unicodeEllipsis` |
| `ocrLowercaseL` | `lt's`, `l'm`, `l'll`, `lT lS` | `It's`, `I'm`, `I'll`, `IT IS` |
| `ocrPipe` | `\|t was`, `wi\|\|` | `It was`, `will` |
| `ocrZeroInWords` | `D0N'T`, `n0rth` | `DON'T`, `north`. Not in `007` or `2.0` |
| `replaceList` | the words of an `OcrReplaceList` | the replacement |

- **Defaults**: every fix is on, except `replaceList` and `unicodeEllipsis`. The fixes run in the order of `CommonErrorFixer::RULES`. The report holds one `AppliedFix` in `fixes` for each rule that changed a cue.
- **Text runs**: the fixes see text runs, as `replaceText()` does. Only `unbalancedTags` and `emptyTags` change tags. `unbalancedTags` closes a tag at the end of the last line of its cue. It removes a closing tag without an opening tag.
- **Language**: `language` takes a code such as `en`, `de-AT` or `fra`. Null takes the `language` metadata of the subtitle. English, German, French and Spanish have their own rules for I and l. Other languages get only the rules that apply to all languages, for example `lT` to `IT`.
- **I and l**: OCR reads a capital I as l when the font draws both the same. `ocrLowercaseL` changes an `l` at the start of a word before a consonant: `lch` to `Ich`, `lsabel` to `Isabel`. French also changes `ll` to `Il`, and keeps `l'hôtel`. Spanish keeps `llega`. English also changes `l`, `l'm`, `l'll`, `l've` and `l'd`. `5 lbs` and `2 l` stay.
- **Image cues**: run the fixes after [OCR](ocr.md). Cues without text lines stay unchanged.
- **Empty cues**: a cue that the replace list empties goes. `cueIndex` is the index before the removal.
- **Limits**: a fix sees one text run, so it does not find `l<i>t's</i>`. A 0 that stands for another letter, such as `B0ro` for `Büro`, becomes `o`.

### OCR replace lists
`OcrReplaceList::fromSubtitleEditXml()` reads an OCR replace list of [Subtitle Edit](https://github.com/SubtitleEdit/subtitleedit), such as `eng_OCRFixReplaceList_User.xml`. The package ships no list. Pass the arrays to `new OcrReplaceList(wholeWords: ['Teh' => 'The'])` to build a list in code.

| Section | Replaces |
|:--- |:--- |
| `WholeWords` | a word between spaces, also with the punctuation around it, such as `"Teh,` |
| `PartialWordsAlways` | a part of any word, before the `WholeWords` lookup |
| `WholeLines` | the whole visible text of a line |
| `BeginLines` | the start of a line, after a dialogue dash or a quote, and the start of a sentence after `. `, `! ` or `? ` |
| `EndLines` | the end of the cue. It adds no period when the next cue starts with a lower case letter within 0.6 s |
| `PartialLines` | a text that starts and ends at a space, a punctuation mark or the line edge |
| `PartialLinesAlways` | any part of a line |
| `RegularExpressions` | a .NET pattern, run with PCRE on each text run. `^` and `$` match at the tags around the run |

- **Rules**: the sections work as in `OcrFixReplaceList2.cs` of Subtitle Edit at commit [`e1b8546`](https://github.com/SubtitleEdit/subtitleedit/blob/e1b854665b40bf6e04271c2ec64084947060e632/src/libuilogic/Ocr/FixEngine/OcrFixReplaceList2.cs).
- **Skipped**: `PartialWords` and `RegularExpressionsIfSpelledCorrectly` need a spell checker. `Removed...` sections change the list that Subtitle Edit ships. A regular expression that PCRE rejects, or a replacement with a named group such as `${name}`, is skipped.
- **Errors**: XML that does not parse throws `ParsingException` with the line number. An invalid PCRE pattern in the constructor throws `InvalidArgumentException`.
