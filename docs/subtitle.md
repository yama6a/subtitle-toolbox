# Subtitles and cues

A `Subtitle` holds a sorted list of `SubtitleCue` objects plus the data of the file: metadata, comments and format data.

## Metadata, comments and cue identifiers
Parsers fill these fields where their format has them, and formatters write them back. [formats.md](formats.md) lists what each format keeps.

```php
use SubtitleToolbox\Subtitle;

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

## Alignment and format data
```php
$cue->setAlignment(8);                                  // top center
$cue->setFormatData('ass', ['style' => 'Sign']);
$subtitle->getFormatData('ass');                        // [] when not set
```

- **Alignment**: a number from 1 to 9 in numeric keypad layout. 1 is bottom left, 2 is bottom center, 8 is top center. `null` means the format default, bottom center.
- **Format data**: the data of a format that has no shared field, for example ASS styles. Only the formatter of the same format reads it. The key is the lowercase file extension of the format, for example `ass` or `vtt`. [formats.md](formats.md) lists the keys of each format.

## Finding cues
```php
use SubtitleToolbox\SubtitleCue;

count($subtitle);                               // 612
foreach ($subtitle as $index => $cue) { }       // in index order

$subtitle->getCuesAt(83.2);                     // [41 => $cue], the cues on screen at 83.2 s
$subtitle->getCueIndexAt(83.2);                 // 41, or null when no cue is on screen
$subtitle->getCuesBetween(600, 660);            // the cues that overlap 600 s to 660 s, not cut
$subtitle->findCues(fn (SubtitleCue $cue) => str_contains($cue->getText(), 'Paris'));
$subtitle->filterCues(fn (SubtitleCue $cue) => $cue->getEnd() - $cue->getStart() >= 0.5);   // removes the other cues
```

- **On screen**: a cue is on screen at time `t` when `start <= t < end`. A cue from 4.0 s to 6.0 s is on screen at 4.0 s, but not at 6.0 s. A cue with the same start and end is never on screen.
- **Overlaps**: cues can overlap, so `getCuesAt()` returns an array. `getCueIndexAt()` returns the lowest index of these cues.
- **Keys**: `getCuesAt()`, `getCuesBetween()` and `findCues()` keep the cue index as the array key.
- **Filter**: `filterCues()` moves a comment before a removed cue to the next kept cue, and then calls `reIndexCues()`.
- **No array access**: `$subtitle[3]` does not work. Use `getCues()`, `addCue()` and `removeCue()`, so the cue indexes and comments stay correct.
- **Structure check**: `getErrors()` returns one message per problem. It reports a subtitle without cues, a cue that starts before the previous cue ends, a cue that ends before it starts, and a gap in the cue indexes. For reading and timing rules, use [validation](validation.md).

## Forced cues
A **forced cue** shows also when the viewer has turned subtitles off, for example the translation of a sign. Apple and Netflix take a full subtitle file and a separate file with only the forced cues.

```php
use SubtitleToolbox\Format;

$subtitle = Subtitle::fromString(file_get_contents('movie.itt'), Format::Itt);   // <p itts:forcedDisplay="true">Sector 7 ahead</p>
$subtitle->getCues()[3]->isForced();                                             // true
$subtitle->getCues()[4]->setForced(true);
$forced = $subtitle->forcedOnly();                              // a new Subtitle with copies of the forced cues
file_put_contents('movie.forced.itt', $forced->toString(Format::Itt));
```

| Format | Read | Write |
|:--- |:--- |:--- |
| TTML, IMSC, DFXP | `itts:forcedDisplay="true"` on `p`, `span`, `div`, `body`, the region or a referenced style | the same attribute on `p` |
| iTT | as TTML | the same attribute on `p` |
| PGS, VobSub | the forced flag of the object or unit | PGS: the forced flag of the object. VobSub: no formatter |
| MKV | the forced flag of the track, see [mkv.md](mkv.md) | no formatter |
| JSON | `forced` | `forced` |
| other formats | no flag | the flag is lost |

- **Default**: a cue is not forced.
- **TTML spans**: one forced `span` makes the whole cue forced. The formatter then writes the flag on the `p`, so the whole paragraph becomes forced.
- **`forcedOnly()`**: works as `slice()`. The copy keeps the metadata, the format data and the comments before the forced cues. The original stays unchanged.
- **OCR**: OCR keeps the flag of an image cue.

## Statistics
```php
use SubtitleToolbox\SubtitleStatistics;

$stats = SubtitleStatistics::of($subtitle);
$stats->getCueCount();             // 612
$stats->getWordCount();            // 4870
$stats->getCharacterCount();       // 25310
$stats->getTotalDisplayTime();     // 1742.5, the sum of the cue durations in seconds
$stats->getSpan();                 // 2688.0, the seconds from the first start to the last end
$stats->getCharactersPerSecond();  // ['min' => 3.1, 'average' => 14.5, 'max' => 31.2]
$stats->getWordsPerMinute();       // ['min' => 40.0, 'average' => 168.0, 'max' => 390.0]
$stats->getCharactersPerLine();    // ['min' => 2.0, 'average' => 31.0, 'max' => 47.0]
$stats->getGap();                  // ['min' => 0.0, 'average' => 2.9, 'max' => 41.0]
$stats->getMostUsedWords(10);      // ['you' => 211, 'the' => 160, ...]
json_encode($stats->toArray());    // all numbers and the 10 most used words
```

- **Characters**: the count leaves out tags and leading and trailing spaces. An entity such as `&amp;` and a UTF-8 letter of several bytes count as one character. [Validation](validation.md) counts the same way.
- **Words**: the text without tags, split at whitespace. A dialogue dash counts as a word. `getMostUsedWords()` removes punctuation at the start and end of each word and compares in lower case.
- **Integer keys**: PHP stores a word that is a plain integer such as `2024` as an int key. Cast a key to string before string use.
- **Cues without text**: an image cue counts in `getCueCount()`, the display time, the span and the gaps. The text numbers leave it out.
- **Reading speed**: a cue with a duration of 0 has no characters per second and no words per minute.
- **Gap**: the start of a cue minus the latest end of the earlier cues. An overlap gives a negative gap.
- **No cues**: all numbers are 0.
