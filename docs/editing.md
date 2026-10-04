# Editing cues

All methods on this page change the `Subtitle` in place and return it, unless the text says otherwise. To keep the original, edit a copy. `clone` copies the cues too:

```php
$copy = clone $subtitle;
$copy->shift(2);                                     // $subtitle keeps its times
```

## Retiming
```php
use SubtitleToolbox\FrameRate;

$subtitle->shift(-2.5);                              // all cues 2.5 s earlier
$subtitle->shift(3, 600);                            // only cues that start at 600 s or later
$subtitle->scale(1.001);                             // multiply all times by 1.001
$subtitle->convertFrameRate(25, 23.976);             // subtitle for a 25 fps video, video is 23.976 fps
$subtitle->syncByTwoPoints(10, 12, 6260, 6005);      // 10 s becomes 12 s, 6260 s becomes 6005 s
(new FrameRate(23.976))->framesToSeconds(1000);      // about 41.708
```

- **Negative times**: a start or end time that becomes negative becomes 0. The cue stays in the subtitle.
- **Word timestamps**: these 4 methods also move the word timestamps in the cue text, such as `<00:00:02.000>`. `merge()` with an offset and `slice()` with `$moveToZero` move them too. A word timestamp that becomes negative becomes 0.
- **Cue boundaries**: `fixOverlaps()`, `extendShortCues()`, the [shot change timing](#shot-changes-and-gaps) and the snap of `DualSubtitle` move a start or end time without moving the speech, so the word timestamps keep their times.
- **Speech-to-text format data**: the format data of Whisper, Deepgram, AssemblyAI, AWS Transcribe and Google input is a copy of the source file and keeps its times. Read the word times from the word timestamps in the cue text.
- **Other ways to sync**: [sync.md](sync.md) finds the offset and scale from a reference subtitle or the speech in the audio.

## Merge, slice, split and join
```php
$part1->merge($part2, 3130);                    // appends part 2, 3130 s later
$clip = $subtitle->slice(600, 1200, true);      // a new Subtitle with the cues from 600 s to 1200 s, moved to start at 0
$subtitle->splitCue(4, 63.5, 1);                // cue 4 becomes two cues at 63.5 s, line 1 in the first
$subtitle->joinCues(4, 5);                      // one cue with the lines of cue 4 and 5
$subtitle->removeDuplicateCues();               // joins touching cues with the same text
```

- **Merge**: the metadata and the format data of `$part1` win over those of `$part2`. The comments of both files stay before their cues. At the same place, the comments of `$part1` come first.
- **Slice**: a cue that crosses the start or end time gets cut there. The copy keeps the metadata, the format data and the comments before the kept cues. The original stays unchanged.
- **Split and join**: the first cue keeps its identifier. A comment before a joined cue moves before the result.

## Overlaps, short cues and line breaks
```php
$gap = (new FrameRate(24))->framesToSeconds(2);   // about 0.083 s

$subtitle->fixOverlaps($gap);                     // end each cue at least $gap before the next cue starts
$subtitle->extendShortCues(0.833, $gap);          // show each cue for at least 0.833 s where the next cue allows it
$subtitle->wrapLines(42);                         // at most 42 characters per line, at most 2 lines
$subtitle->unwrapLines();                         // join the lines of each cue with a space
```

- **Start times**: these fixes move only end times. `fixOverlaps()` ends a cue at its own start when the gap does not fit. `extendShortCues()` never creates an overlap and never makes a cue shorter.
- **Line breaks**: `wrapLines()` changes only cues with a longer line or with more lines than allowed. It uses the fewest lines that fit and makes them about equal in length. When the text does not fit, the lines get longer than the limit.
- **Characters**: tags count 0 characters, and an entity such as `&amp;` counts 1. `wrapLines()` breaks only at spaces outside tags. It closes the open core markup tags at a break and opens them again on the next line.
- **Text without spaces**: Chinese or Japanese text has no break points, so `wrapLines()` keeps such a line long.

## Short cues
Speech-to-text output and fast dialogue often have many cues under 1 s. `mergeShortCues()` joins such a cue with its neighbour when the joined cue still fits the limits.

```php
use SubtitleToolbox\MergeShortCuesOptions;

// 00:01:02,100 --> 00:01:02,600  Wait.
// 00:01:02,640 --> 00:01:03,300  Where are you
// 00:01:03,320 --> 00:01:04,100  going?
$subtitle->mergeShortCues(new MergeShortCuesOptions(
    maxCharactersPerLine: 42,
    maxLines: 2,
    maxGap: 0.25,
    maxDuration: 7,
));
// 00:01:02,100 --> 00:01:04,100  Wait. Where are you going?
```

| Option | Default | Meaning |
|:--- |:--- |:--- |
| `maxCharactersPerLine` | 42 | the line length of the joined text |
| `maxLines` | 2 | the line count of the joined text |
| `maxGap` | 0.25 | seconds from the end of one cue to the start of the next |
| `maxDuration` | 7 | seconds from the start to the end of the joined cue |
| `minDuration` | 1 | a cue shorter than this many seconds is short |
| `minCharacters` | null | a cue with fewer visible characters is short. Null turns the rule off |
| `maxCharactersPerSecond` | null | the reading speed of the joined cue. Null turns the rule off |
| `keepSentenceEnds` | false | join only when the first cue does not end with `.`, `?` or `!` |
| `sameSpeakerOnly` | false | join each cue with the next cue of the same `<v>` speaker, short or not, with no `maxDuration` limit. A cue without a `<v>` tag never joins |

- **Order**: the method walks the cues in start time order. It joins a short cue with the next cue. When the next cue does not fit, it tries the previous cue. A joined cue that is still short joins again.
- **Never joined**: cues with different `<v>` speakers, different alignments or different forced flags, and image cues. A cue without a `<v>` tag and a cue with one have different speakers. Alignment `null` and alignment 2 count as the same.
- **Joined cue**: it keeps the start, the identifier, the alignment and the format data of the first cue, and the end of the last cue. A comment before a joined cue moves before the result.
- **Text**: the lines are joined with a space and wrapped as `wrapLines()` does. A line that starts with a dialogue dash stays on its own line, and then each such line must fit on one line. When both cues start with a `<v>` tag of the same speaker, the joined text keeps only the first tag.

## Long cues
Speech-to-text tools such as Whisper write segments of 10 s and more. `wrapLines()` makes the lines shorter, but the cue stays too long to read. `Resegmenter` with `ResegmentMode::SplitLong` splits such a cue into cues that fit the limits.

```php
use SubtitleToolbox\Resegmenting\ResegmentMode;
use SubtitleToolbox\Resegmenting\Resegmenter;
use SubtitleToolbox\Resegmenting\ResegmentOptions;

// 00:00:00,000 --> 00:00:11,050  The tensor operators are optimized heavily for Apple silicon CPUs. Depending on
//                                the computation size, Arm Neon SIMD instrisics or CBLAS Accelerate framework routines are used.
$report = Resegmenter::apply($subtitle, new ResegmentOptions(ResegmentMode::SplitLong, maxCharactersPerLine: 42, maxLines: 2));
// 00:00:00,000 --> 00:00:04,231  The tensor operators are optimized heavily for Apple silicon CPUs.
// 00:00:04,231 --> 00:00:06,441  Depending on the computation size,
// 00:00:06,441 --> 00:00:11,050  Arm Neon SIMD instrisics or CBLAS Accelerate framework routines are used.
$report->cuesBefore;   // 1
$report->cuesAfter;    // 3

Resegmenter::apply($subtitle, new ResegmentOptions(ResegmentMode::ByWords, maxWordGap: 0.6));
```

`ResegmentMode::ByWords` drops the cue boundaries and builds new cues from the word timestamps, for example from Whisper JSON read with `ReadOptions::$wordTimestamps`. Each cue then holds one sentence, or as much of it as fits.

| Option | Default | Meaning |
|:--- |:--- |:--- |
| `mode` | required | `ResegmentMode::SplitLong` or `ResegmentMode::ByWords` |
| `maxCharactersPerLine` | 42 | the line length of a cue |
| `maxLines` | 2 | the line count of a cue |
| `maxDuration` | 7 | seconds from the start to the end of a cue |
| `minDuration` | 1 | `SplitLong` never makes a cue shorter than this many seconds |
| `maxCharactersPerSecond` | null | the reading speed of a cue. Null turns the rule off |
| `maxWordGap` | 0.6 | `ByWords` ends a cue at a pause of this many seconds or more |

- **Limits**: a cue breaks the limits when its text does not fit `maxLines` lines of `maxCharactersPerLine` characters, as `wrapLines()` wraps it. It also breaks them above `maxDuration` or `maxCharactersPerSecond`.
- **Break points**, best first: a sentence end, a clause end, then the space closest to the middle. Among break points of the same kind, the one closest to the middle wins. A full stop before a word in lower case, as in "e.g. this", is no sentence end.
- **Splitting**: `SplitLong` splits a cue in two at the best break point. It splits each part again while the part breaks a limit. A cue stays unchanged when no break point keeps both parts at `minDuration` or longer.
- **Times**: a new cue starts at the word timestamp of its first word. Without one, the time splits in proportion to the visible characters.
- **Text without spaces**, such as Japanese, splits after CJK punctuation and at word timestamps.
- **Regrouping**: `ByWords` ends a cue after a sentence end, before a pause of `maxWordGap` seconds, and before a word that would break a limit. It never joins words of cues with different `<v>` speakers, alignments or forced flags. Cues without word timestamps stay unchanged.
- **Tags**: a core markup tag that is open at a break closes at the end of the first cue and opens again in the next cue.
- **Unchanged**: image cues and cues of one word.
- **Cue data**: a new cue keeps the alignment, forced flag and format data of its source cue. Only the cue with the first word of a source cue keeps its identifier.

## Shot changes and gaps
A shot change is the frame where the picture cuts to a new shot. `ShotChangeTiming` times cues to the shot changes and closes small gaps, as the [Netflix Subtitle Timing Guidelines](https://partnerhelp.netflixstudios.com/hc/en-us/articles/360051554394) require. You pass the shot change times. The library does not read video.

```php
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Timing\ShotChanges;
use SubtitleToolbox\Timing\ShotChangeTiming;

// ffmpeg -i in.mp4 -vf "select='gt(scene,0.3)',showinfo" -f null - 2> scenes.log
$shotChanges = ShotChanges::fromFfmpegLog(file_get_contents('scenes.log'));   // [12.5, 62.5, 70.0]
$shotChanges = ShotChanges::fromText("12.5\n00:01:02.500\n70\n");               // the same times

$report = ShotChangeTiming::apply($subtitle, new ShotChangeOptions(
    frameRate: 24,
    shotChanges: $shotChanges,   // seconds. Without shot changes, apply() only closes small gaps.
    snapWindow: 12,        // frames, default half a second: 12 at 23.976, 24 and 25 fps, 15 at 29.97 fps
    minGapFrames: 2,       // frames between a cue and the next cue or shot change, default 2
    chain: true,           // true (default) closes small gaps, false keeps them
    minDuration: 20,       // frames, default 20
));
$report->movedStarts;   // the cue starts that moved by one frame or more
$report->movedEnds;     // the cue ends that moved by one frame or more
```

| Rule | Before, at 24 fps | After |
|:--- |:--- |:--- |
| An in-time up to `snapWindow` frames after a shot change moves to the shot change. | shot change 62.500, cue starts 62.708 | starts 62.500 |
| An out-time up to `snapWindow` frames before a shot change ends `minGapFrames` before it. | shot change 70.000, cue ends 69.750 | ends 69.917 |
| **Chaining**: a gap of more than `minGapFrames` and less than `snapWindow` frames closes to `minGapFrames`. The earlier cue ends later. | cue A ends 10.000, cue B starts 10.292 | A ends 10.208 |

- **Frames**: all cue times of the result fall on frames of `frameRate`, rounded to milliseconds.
- **Blocked moves**: a move does not happen when it makes a cue shorter than `minDuration`. It also does not happen when it brings the cue closer than `minGapFrames` to the cue before or after it. A move that makes a short cue longer still happens.
- **Chaining across a cut**: `apply()` does not chain a gap that holds a shot change. Without `shotChanges`, it chains every small gap.
- **Input**: `fromFfmpegLog()` reads the `pts_time:` values. `fromText()` reads one time per line, in seconds or as `hh:mm:ss.mmm`, and skips empty lines. Both return the times sorted, without duplicates.

## Dual subtitles
A dual subtitle shows two languages at the same time, for example for language learners. Most players show only one subtitle track, so both languages go into one file.

```php
use SubtitleToolbox\Dual\DualSubtitle;
use SubtitleToolbox\Dual\DualSubtitleMode;
use SubtitleToolbox\Dual\DualSubtitleOptions;

$english = Subtitle::fromStringAutoDetectFormat(file_get_contents('movie.en.srt'));
$german  = Subtitle::fromStringAutoDetectFormat(file_get_contents('movie.de.srt'));

$dual = DualSubtitle::merge($english, $german, new DualSubtitleOptions(secondaryStyle: 'i'));
$dual = DualSubtitle::merge($english, $german, new DualSubtitleOptions(
    mode: DualSubtitleMode::TopBottom,              // English at the bottom, German at the top
    snapTolerance: 0.25,                            // seconds
    secondaryStyle: 'font color="#ffff00"',
    secondaryAlignment: 8,
));
```

| Mode | Result for `00:00:01.000 --> 00:00:04.000 Where are you going?` and `00:00:01.200 --> 00:00:03.900 Wohin gehst du?` | Formats |
|:--- |:--- |:--- |
| `stack`, the default | one cue from 1.000 s to 4.000 s with the lines `Where are you going?` and `<i>Wohin gehst du?</i>` with `secondaryStyle: 'i'` | all |
| `topBottom` | the English cue with alignment `null`, and the German cue with alignment 8 from 1.000 s to 4.000 s | SubRip with `{\an8}`, WebVTT, ASS, TTML, iTT, EBU STL and SCC. The other formats do not write the alignment |

- **Stack**: each secondary cue joins the primary cue that it overlaps most. The joined cue spans from the earlier start to the later end. A cue without an overlap stays a cue of its own.
- **Top and bottom**: a secondary start or end time moves to the closest primary start or end time within `snapTolerance`. So the two languages appear and disappear together. A cue keeps its times when both would move to the same time.
- **Secondary style**: a core markup tag, such as `i` or `font color="#ffff00"`, around each secondary line. WebVTT has no font colour, so its formatter drops the `font` tag.
- **Copied data**: the result is a new `Subtitle`. Metadata, comments and format data come from the primary subtitle. The secondary cues lose their identifiers and format data. The language becomes `en+de` when both subtitles have a language.
