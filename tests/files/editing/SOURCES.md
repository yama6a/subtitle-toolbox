# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `film_part1.srt` | Written for this repository. Part 1 of a film as SubRip, with UTF-8 BOM and CR LF line endings. | MIT |
| `film_part2.srt` | Written for this repository. Part 2 of the same film, without BOM, with LF line endings and an `{\an8}` tag. | MIT |
| `film_merged.srt` | Written for this repository. Expected `SubRipFormatter` output after merging part 2 into part 1 at 00:52:10.000. | MIT |
| `harbour_tour.vtt` | Written for this repository. WebVTT in the shape of an HLS stream: an `X-TIMESTAMP-MAP` header, a cue repeated across a segment boundary, `NOTE` comments and cue settings. | MIT |
| `harbour_tour_deduplicated.vtt` | Written for this repository. Expected `WebVttFormatter` output after `removeDuplicateCues()`. | MIT |
| `harbour_tour_split.vtt` | Written for this repository. Expected `WebVttFormatter` output after `splitCue(2, 9.5, 1)`. | MIT |
| `harbour_tour_joined.vtt` | Written for this repository. Expected `WebVttFormatter` output after `joinCues(3, 4)`. | MIT |
| `harbour_tour_slice.vtt` | Written for this repository. Expected `WebVttFormatter` output after `withSlice(6, 16, true)`. | MIT |
