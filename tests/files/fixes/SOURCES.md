# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `own_overlaps_and_short_cues.srt` | Written for this repository in the shape of a SubRip file from a subtitle editor: BOM, CR LF, italic and bold tags, overlaps and cues shorter than 0.5 s. | MIT |
| `own_overlaps_and_short_cues_fixed.srt` | Written for this repository. Expected `SubRipFormatter` output for `own_overlaps_and_short_cues.srt` after `fixOverlaps(0.083)`, `extendShortCues(0.833, 0.083)` and `wrapLines(42)`. | MIT |
