# Sources

Every file is written for this repository in the shape of broken subtitle downloads.

| File | Damage | License |
|:--- |:--- |:--- |
| `missing_cue_numbers.srt` | two cues without a cue number, UTF-8 BOM, CR LF | MIT |
| `bad_timestamp.srt` | a letter in an end time, and a `->` arrow, CR LF | MIT |
| `missing_empty_line.srt` | no empty line before cue 2 and before cue 3, cue 3 without a number, LF | MIT |
| `text_before_first_cue.srt` | a download site banner before cue 1, CR LF | MIT |
| `truncated_last_cue.srt` | the file ends inside the time line of cue 3, LF | MIT |
| `mixed_line_endings.srt` | CR LF, LF and CR CR LF in one file | MIT |
| `bad_timestamp.vtt` | a comma in an end time, CR LF | MIT |
| `missing_empty_line.vtt` | no empty line after the header lines, nor between two cue pairs, LF | MIT |
| `text_before_first_cue.vtt` | a download site banner after the header, UTF-8 BOM, CR LF | MIT |
| `truncated_last_cue.vtt` | the file ends inside the time line of the last cue, LF | MIT |
| `mixed_line_endings.vtt` | CR LF, LF and CR CR LF in one file | MIT |
| `bad_timestamp.sbv` | an end time with two millisecond digits, LF | MIT |
| `missing_empty_line.sbv` | no empty line between cue 1 and cue 2, LF | MIT |
| `text_before_first_cue.sbv` | a note line before cue 1, LF | MIT |
| `truncated_last_cue.sbv` | the file ends inside the time line of cue 3, LF | MIT |
| `mixed_line_endings.sbv` | CR LF, LF and CR CR LF in one file | MIT |
| `release_name.sub` | a release name before the `{1}{1}25` frame rate line, a cue with a letter as start frame, CR LF | MIT |
| `broken_events.ass` | no `Format:` line in `[Events]`, an event with 4 fields, a letter in an end time, UTF-8 BOM, CR LF | MIT |
| `bad_time_line.sub` | SubViewer 2, a note line before the header, a letter in the end time of cue 2, CR LF | MIT |
| `bad_timing_line.mpsub` | no `FORMAT=` line, a letter as duration, a last cue without text, LF | MIT |
| `broken_time_tag.lrc` | a letter in the seconds of a time tag, CR LF | MIT |
| `bad_sync_start.smi` | a `SYNC` tag with an empty `Start` attribute, CR LF | MIT |
| `bad_begin.ttml` | a letter in a `begin` time, a `p` without `end` or `dur`, LF | MIT |
| `bad_time_code.stl` | written by `generate-stl.php`. Frame 30 in a 25 fps time code out, the last TTI block cut off after 60 bytes | MIT |
| `missing_end.json` | a cue without `end`, a cue with a number as line, a comment after the first broken cue, LF | MIT |
| `missing_segment_end.whisper.json` | the OpenAI API shape, a segment without `end`, LF | MIT |
| `absurd_hours.vtt` | a cue with 20 hour digits between two good cues, LF | MIT |
| `absurd_hours.sbv` | a cue with 20 hour digits between two good cues, LF | MIT |
| `absurd_hours.ass` | a `Dialogue` line with 20 hour digits between two good lines, LF | MIT |
| `absurd_hours.csv` | a row with 20 hour digits between two good rows, LF | MIT |
| `absurd_hours.ttml` | a `p` with 20 hour digits and a `p` with an `end` of 20 digits in hours, LF | MIT |
| `absurd_hours.sub` | SubViewer 2. A timing line with 20 hour digits between two good cues, LF | MIT |
| `absurd_hours_tmplayer.txt` | TMPlayer. A line with 20 hour digits between good lines, LF | MIT |
| `absurd_hours.html` | an HTML transcript. A `time` with 20 hour digits between two good ones, LF | MIT |
