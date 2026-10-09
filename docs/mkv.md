# MKV and WebM subtitle tracks

Media servers and subtitle managers get MKV files with embedded subtitles. The library reads the subtitle tracks of MKV and WebM files in PHP, without `ffmpeg` or `mkvextract`. It reads only. In the command line tool, `info movie.mkv` lists the tracks and `convert movie.mkv --to srt -o out.srt --track 3` reads one. See [cli](cli.md).

```php
use SubtitleToolbox\Subtitle;

foreach (Subtitle::tracks('/media/movie.mkv') as $track) {
    echo "$track->number $track->codecId $track->language $track->name", PHP_EOL;   // 3 S_TEXT/UTF8 de Deutsch (Forced)
}
Subtitle::loadTrack('/media/movie.mkv', 3)->save('movie.de.srt');
Subtitle::loadAutoDetectFormat('/media/one-track.webm');       // reads the only subtitle track
```

`MatroskaReader` is in the namespace `SubtitleToolbox\Container\Matroska`. Its `open()` takes a seekable stream resource too: `MatroskaReader::open($stream)->extract(3)`.

| Codec | Becomes |
|:--- |:--- |
| `S_TEXT/UTF8` | SubRip cues |
| `S_TEXT/ASS`, `S_TEXT/SSA` | an ASS or SSA subtitle, as `mkvextract` writes it |
| `S_TEXT/WEBVTT` | a WebVTT subtitle with its header, cue settings, identifiers and comments |
| `S_HDMV/PGS` | image cues from `PgsParser`, see [ocr.md](ocr.md#pgs) |

- **Tracks**: `Subtitle::tracks()` lists only tracks of type subtitle. It returns `SubtitleTrack` objects from the namespace `SubtitleToolbox\Container`. Other containers return the same type.

| Property | Type | MKV value |
|:--- |:--- |:--- |
| `container` | `ContainerFormat` | `ContainerFormat::Matroska` |
| `number` | `int` | `TrackNumber` |
| `codecId` | `string` | `CodecID`, for example `S_TEXT/UTF8` |
| `format` | `?Format` | the format of the codec in the table above, null for a codec that the reader does not extract |
| `language` | `string` | see **Language** below |
| `name` | `?string` | `Name` |
| `default` | `bool` | `FlagDefault` |
| `forced` | `bool` | `FlagForced` |

- **Format**: `getFormat()` of the subtitle is the format of the codec in the table.
- **Detection**: `loadAutoDetectFormat()` and `fromStringAutoDetectFormat()` know an MKV or WebM file by its first 4 bytes, not by its extension.
- **Language**: the reader takes `LanguageBCP47`. Without it, the reader takes `Language`. Without either, the language is `eng`, as the spec defines. `loadTrack()` puts it into the `language` metadata.
- **Forced**: on a track with the forced flag, `loadTrack()` sets the forced flag of every cue. PGS cues also keep the forced flag of their objects.
- **End times**: a text block without a duration ends at the start of the next block of the track. The last such block lasts [`ReadOptions::$lastCueDuration`](read-options.md).
- **Compression**: the reader reads zlib compression and header stripping. It throws `ParsingException` for bzlib compression, LZO compression and encrypted tracks.
- **Live recordings**: the reader accepts a file with elements of unknown size, as live recordings write them.
- **Memory**: the reader skips video and audio data, so memory grows with the subtitle track, not with the file. In the tests, a 64 MB file with 64 cues needs less than 4 MB.
- **Speed**: the reader walks the whole file for each `loadTrack()` call. A 2-hour, 4 GB file takes about 3 s of CPU time. On a network volume it takes 30 to 45 s.
- **Errors**: `loadTrack()`, `MatroskaReader::extract()` and `MatroskaReader::trackFormat()` throw `InvalidArgumentException` for a number that is not a subtitle track. `trackFormat()` returns null for a codec that the reader does not extract, such as `S_VOBSUB`. `loadTrack()` and `extract()` throw `ParsingException` in these cases:
  - Another codec, such as `S_VOBSUB`.
  - Laced subtitle blocks.
  - A file that is not Matroska or WebM.
- **Spec**: [Matroska elements](https://www.matroska.org/technical/elements.html), [Matroska subtitles](https://www.matroska.org/technical/subtitles.html).
