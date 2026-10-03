# MKV and WebM subtitle tracks

Media servers and subtitle managers get MKV files with embedded subtitles. `MatroskaReader` reads the subtitle tracks of MKV and WebM files in PHP, without `ffmpeg` or `mkvextract`. It reads only. In the command line tool, `info movie.mkv` lists the tracks and `convert movie.mkv out.srt --track 3` reads one. See [cli](cli.md).

```php
use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\Format;

$mkv = MatroskaReader::open('/media/movie.mkv');                 // a path or a seekable stream resource
foreach ($mkv->getSubtitleTracks() as $track) {
    echo "$track->number $track->codecId $track->language $track->name", PHP_EOL;   // 3 S_TEXT/UTF8 de Deutsch (Forced)
}
$german = $mkv->extract(3);                                     // a Subtitle
file_put_contents('movie.de.srt', $german->toString(Format::SubRip));
```

| Codec | Becomes |
|:--- |:--- |
| `S_TEXT/UTF8` | SubRip cues |
| `S_TEXT/ASS`, `S_TEXT/SSA` | an ASS or SSA subtitle, as `mkvextract` writes it |
| `S_TEXT/WEBVTT` | a WebVTT subtitle with its header, cue settings, identifiers and comments |
| `S_HDMV/PGS` | image cues from `PgsParser`, see [ocr.md](ocr.md#pgs) |

- **Tracks**: `getSubtitleTracks()` lists only tracks of type subtitle. `MatroskaTrack` has `number`, `codecId`, `language`, `name`, `default` and `forced`.
- **Language**: `LanguageBCP47`, else `Language`, else `eng`, as the spec defines. `extract()` puts it into the `language` metadata.
- **Forced**: on a track with the forced flag, `extract()` sets the forced flag of every cue. PGS cues also keep the forced flag of their objects.
- **End times**: a text block without a duration ends at the start of the next block of the track. The last such block lasts `MatroskaReader::DEFAULT_LAST_CUE_DURATION`, 5 s.
- **Compression**: the reader reads zlib compression and header stripping. It throws `ParsingException` for bzlib and LZO compression and for encryption.
- **Live recordings**: the reader accepts a file with elements of unknown size, as live recordings write them.
- **Memory**: the reader skips video and audio data, so memory grows with the subtitle track, not with the file. A 4 GB file needs a few MB.
- **Speed**: the reader walks the whole file for each `extract()` call. A 2-hour, 4 GB file takes about 3 s of CPU time. On a network volume it took 30 to 45 s.
- **Errors**: `extract()` throws `InvalidArgumentException` for a number that is not a subtitle track. It throws `ParsingException` for other codecs such as `S_VOBSUB`, for laced subtitle blocks and for a file that is not Matroska or WebM.
- **Spec**: [Matroska elements](https://www.matroska.org/technical/elements.html), [Matroska subtitles](https://www.matroska.org/technical/subtitles.html).
