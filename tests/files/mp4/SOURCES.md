# Sources

All files here are written for this repository. No `ffmpeg` was available, so a PHP script writes them. `generator/Mp4Fixtures.php` builds the files with `generator/Mp4FixtureWriter.php`, a small box writer that follows ISO/IEC 14496-12 and 3GPP TS 26.245. Run `php tests/files/mp4/generator/generate.php` to write them again. `Mp4ReaderTest` checks that the files match the generator output.

| File | Source | License |
|:--- |:--- |:--- |
| `text_tracks.mp4` | Written for this repository. The moov box follows the mdat box. A video track and four subtitle tracks: `tx3g` in English with a name, gap samples, a `styl` box, UTF-16 text and CR LF, 2 samples per chunk. `tx3g` with `elng` `fr-CA`, the forced display flag, version 1 `tkhd` and `mdhd`, `co64` and `stz2`. A `c608` track, and an `enct` track with a `sinf` box | MIT |
| `one_track.mp4` | Written for this repository. The moov box comes first. A video track and one `tx3g` track | MIT |
