# Sources

All files here are written for this repository. No permissively licensed MKV file with neutral subtitle text was found, and no `mkvmerge` was available, so a PHP script writes them.

`generator/MkvFixtures.php` builds the files with `generator/MkvFixtureWriter.php`, a small EBML writer. Run `php tests/files/mkv/generator/generate.php` to write them again. `MatroskaReaderTest` checks that the files match the generator output. ffmpeg 7.0 opens all files and lists their tracks.

| File | Source | License |
|:--- |:--- |:--- |
| `text_tracks.mkv` | Written for this repository. A video track, a laced audio track and six subtitle tracks: S_TEXT/UTF8 with `LanguageBCP47` and the forced flag, S_TEXT/ASS with the default flag and ReadOrder out of time order, S_TEXT/WEBVTT with cue settings, an identifier and a comment in `BlockAdditions`, S_TEXT/SSA without an `[Events]` section in `CodecPrivate`, S_DVBSUB, and S_TEXT/UTF8 in SimpleBlocks without duration and language. A block with a negative relative timestamp, a Void element, Cues and Tags | MIT |
| `compressed.mkv` | Written for this repository. S_TEXT/UTF8 and S_TEXT/ASS tracks with zlib compression of the frames and of `CodecPrivate`, header stripping, zlib after header stripping, and bzlib | MIT |
| `unknown_sizes.mkv` | Written for this repository. A live recording: the Segment and the Clusters have an unknown size. `TimestampScale` is 100000, so a tick is 0.1 ms | MIT |
| `seek_head.mkv` | Written for this repository. WebM doc type. `Info` and `Tracks` follow the clusters, and the `SeekHead` points to them. S_TEXT/WEBVTT without `CodecPrivate` | MIT |
| `pgs.mkv` | Written for this repository. Two S_HDMV/PGS tracks with the segments of [../pgs/shapes_1080p.sup](../pgs/SOURCES.md): one segment per SimpleBlock, and one display set per BlockGroup with the forced flag | MIT |
| `vobsub.mkv` | Written for this repository. Two S_VOBSUB tracks with the `en` units of [../vobsub/two-tracks-pal.sub](../vobsub/SOURCES.md). Track 3 has the whole `.idx` header in `CodecPrivate` and SimpleBlocks. Track 4 has only the `size` and `palette` lines, zlib compression and BlockGroups of 1500 ms | MIT |
| `huge_timestamp.mkv` | Written for this repository. A copy of `text_tracks.mkv` with byte 1560 changed from `0xA3` (SimpleBlock) to `0xE7` (Timestamp). The cluster timestamp then overflows the time of every subtitle track | MIT |
