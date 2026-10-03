# Validation

`validate()` checks the cues against reading and timing rules. It returns one `ValidationResult` per broken rule. The command line tool runs the same check, see [cli.md](cli.md#validate).

```php
use SubtitleToolbox\Validation\ValidationRules;

$results = $subtitle->validate(ValidationRules::netflixEnglish(23.976));
$results = $subtitle->validate(ValidationRules::bbc());
$results = $subtitle->validate(new ValidationRules(noUnbalancedTags: true, maxSpeakersPerCue: 2));
$results = $subtitle->validate(new ValidationRules(maxCharactersPerLine: 37, noEmptyCues: true));

$results[0]->getCueIndex();     // 1
$results[0]->getRule();         // 'maxCharactersPerLine', see the ValidationResult::RULE_* constants
$results[0]->getValue();        // 45
$results[0]->getLimit();        // 37
```

| Rule | Limit | Value |
|:--- |:--- |:--- |
| `maxCharactersPerSecond` | characters per second | characters of all lines divided by the duration. `INF` for a cue with text and no duration |
| `maxCharactersPerLine` | characters | one result per line that is too long |
| `maxLinesPerCue` | lines | lines with visible text |
| `minDuration`, `maxDuration` | seconds | end minus start |
| `minGap` | seconds | start minus the latest end of the earlier cues. Overlaps are not gaps |
| `noOverlap` | `true` to check, result limit `null` | seconds of overlap with the earlier cues |
| `noEmptyCues` | `true` to check, result limit `null` | 0 |
| `noDoubleSpaces` | `true` to check, result limit `null` | runs of two or more spaces between words. A non-breaking space counts as a space |
| `noLeadingOrTrailingSpaces` | `true` to check, result limit `null` | lines that start or end with a space or a non-breaking space |
| `noUnbalancedTags` | `true` to check, result limit `null` | `<b>`, `<i>`, `<u>`, `<s>` and `<font>` tags without a partner tag, across all lines of the cue. An open `<v>` needs no `</v>` |
| `dialogueDashStyle` | the dash and the space after it: `'- '`, `'-'`, `"\u{2013} "` and so on. Result limit `null` | lines with a dialogue dash in another style |
| `maxSpeakersPerCue` | speakers | the lines with a dialogue dash or the different `<v>` names, the larger count |
| `maxWordsPerMinute` | words per minute | words divided by the duration. `INF` for a cue with words and no duration |
| `minSecondsPerWord` | seconds per word | duration divided by the words |
| `allowedCharacters` | the allowed characters as a string, or a regular expression character class such as `'[A-Za-z0-9 .,!?]'`. Result limit `null` | characters that are not allowed. A space is always allowed |
| `noAllCapsLines` | `true` to check, result limit `null` | lines with two or more upper case letters and no lower case letter. `<v>` names and text in `[]` or `()` do not count |
| `requireCues` | `true` to check, result limit `null` | 0. The result has the cue index `null` |
| `noUnsortedCues` | `true` to check, result limit `null` | seconds by which the cue starts before the previous cue in the list |
| `noNegativeDuration` | `true` to check, result limit `null` | end minus start, below 0 |
| `noIndexGaps` | `true` to check, result limit `null` | the index that the cue should have. `removeCue($index, false)` leaves a gap, `reIndexCues()` closes it |

- **Off by default**: a rule with the limit `null` or `false` is off.
- **Characters**: the count leaves out tags and leading and trailing spaces. It counts an entity such as `&amp;` as one character and a UTF-8 letter of several bytes as one character.
- **Spaces**: the text rules check the text without tags, with entities decoded. A cue stores runs of spaces as one space. So two spaces come from tags, as in `you? <i> Home</i>`, or from non-breaking spaces.
- **Dialogue dash**: a hyphen, an en dash or an em dash at the start of a line, not followed by a digit or another dash. So `-20 degrees` has no dialogue dash.
- **Words**: text runs between white space, as `SubtitleStatistics` counts them. A lone dash is a word.
- **Milliseconds**: cue times have millisecond precision. So a cue of 0.833 s meets a minimum duration of 5/6 s.

## Presets
| Preset | Limits | Source |
|:--- |:--- |:--- |
| `ValidationRules::netflixEnglish($frameRate)` | 20 characters per second, 42 characters per line, 2 lines, 5/6 s to 7 s, a gap of 2 frames at the frame rate, no overlaps | [English (USA) Timed Text Style Guide](https://partnerhelp.netflixstudios.com/hc/en-us/articles/217350977-English-USA-Timed-Text-Style-Guide), [General Requirements](https://partnerhelp.netflixstudios.com/hc/en-us/articles/215758617), [Subtitle Timing Guidelines](https://partnerhelp.netflixstudios.com/hc/en-us/articles/360051554394) |
| `ValidationRules::bbc()` | 37 characters per line, 180 words per minute, 0.3 s per word | [BBC Subtitle Guidelines](https://www.bbc.co.uk/accessibility/forproducts/guides/subtitles/), sections 3.1 and 4 |
| `ValidationRules::structure()` | `requireCues`, `noUnsortedCues`, `noNegativeDuration`, `noIndexGaps` and `noOverlap` | the cue list of `Subtitle` |

- **Netflix**: 20 characters per second is the limit for adult programs.
- **BBC**: 37 characters is the broadcast line length. 180 words per minute is the upper end of the 160 to 180 that the guide gives. 0.3 s per word allows 200 words per minute, so a cue can break the speed rule and still meet the time per word.
