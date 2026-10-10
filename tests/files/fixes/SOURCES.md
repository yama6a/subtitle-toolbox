# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `own_overlaps_and_short_cues.srt` | Written for this repository in the shape of a SubRip file from a subtitle editor: BOM, CR LF, italic and bold tags, overlaps and cues shorter than 0.5 s. | MIT |
| `own_overlaps_and_short_cues_fixed.srt` | Written for this repository. Expected `SubRipFormatter` output for `own_overlaps_and_short_cues.srt` after `fixOverlaps(0.083)`, `extendShortCues(0.833, 0.083)` and `wrapLines(42)`. | MIT |
| `own_dialogue_dashes.srt` | Written for this repository in the shape of a SubRip file from a subtitle editor: CR LF, italic tags, and cues with two or three dialogue turns, one of them on a single line. | MIT |
| `own_dialogue_dashes_wrapped.srt` | Written for this repository. Expected `SubRipFormatter` output for `own_dialogue_dashes.srt` after `wrapLines(42)`. | MIT |
| `own_dialogue_turns_wrapped.srt` | Written for this repository in the shape of a SubRip file from a subtitle editor: CR LF, italic tags, dialogue turns wrapped over 2 lines, a line that starts with a minus sign and a first line without a dash. | MIT |
| `own_dialogue_turns_unwrapped.srt` | Written for this repository. Expected `SubRipFormatter` output for `own_dialogue_turns_wrapped.srt` after `unwrapLines()`, without BOM. | MIT |
| `own_asr_tight_timing.srt` | Written for this repository in the shape of a SubRip file from speech-to-text: LF, cues that start and end on the speech, a 0.1 s gap, touching cues and a sound cue that overlaps dialogue. | MIT |
| `own_asr_tight_timing_lead.srt` | Written for this repository. Expected `SubRipFormatter` output for `own_asr_tight_timing.srt` after `addLeadInOut(0.2, 0.3, 0.083)`, with the BOM that the formatter writes. | MIT |
