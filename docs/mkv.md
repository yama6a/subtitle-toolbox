# MKV and WebM subtitle tracks

Media servers and subtitle managers get MKV files with embedded subtitles. The library reads the subtitle tracks of MKV and WebM files in PHP, without `ffmpeg` or `mkvextract`. It reads only. In the command line tool, `info movie.mkv` lists the tracks and `convert movie.mkv out.srt --track 3` reads one. See [cli](cli.md).

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

- **Tracks**: `Subtitle::tracks()` lists only tracks of type subtitle. `MatroskaTrack`, in the same namespace, has `number`, `codecId`, `language`, `name`, `default` and `forced`. `describe()` joins them, for example `S_TEXT/UTF8, de, "Deutsch", default`.
- **Format**: `getFormat()` of the subtitle is the format of the codec in the table.
- **Detection**: `loadAutoDetectFormat()` and `fromStringAutoDetectFormat()` know an MKV or WebM file by its first 4 bytes, not by its extension.
- **Language**: `LanguageBCP47`, else `Language`, else `eng`, as the spec defines. `loadTrack()` puts it into the `language` metadata.
- **Forced**: on a track with the forced flag, `loadTrack()` sets the forced flag of every cue. PGS cues also keep the forced flag of their objects.
- **End times**: a text block without a duration ends at the start of the next block of the track. The last such block lasts `ReadOptions::$lastCueDuration`, 5 s by default.
- **Compression**: the reader reads zlib compression and header stripping. It throws `ParsingException` for bzlib and LZO compression and for encryption.
- **Live recordings**: the reader accepts a file with elements of unknown size, as live recordings write them.
- **Memory**: the reader skips video and audio data, so memory grows with the subtitle track, not with the file. A 4 GB file needs a few MB.
- **Speed**: the reader walks the whole file for each `loadTrack()` call. A 2-hour, 4 GB file takes about 3 s of CPU time. On a network volume it takes 30 to 45 s.
- **Errors**: `loadTrack()` throws `InvalidArgumentException` for a number that is not a subtitle track. It throws `ParsingException` for other codecs such as `S_VOBSUB`, for laced subtitle blocks and for a file that is not Matroska or WebM.
- **Spec**: [Matroska elements](https://www.matroska.org/technical/elements.html), [Matroska subtitles](https://www.matroska.org/technical/subtitles.html).
