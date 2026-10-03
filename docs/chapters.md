# Chapters

A chapter list names the parts of a video or a podcast episode. Each chapter is a cue with the title as its only line.

| Format | Format name | Parser and formatter | Example |
|:--- |:--- |:--- |:--- |
| YouTube description text | `ytchapter` | `YouTubeChaptersParser`, `YouTubeChaptersFormatter` | `2:48 Hearing aids` |
| Podcasting 2.0 JSON chapters | `podcast` | `PodcastChaptersParser`, `PodcastChaptersFormatter` | `{"version": "1.2.0", "chapters": [{"startTime": 168, "title": "Hearing aids"}]}` |
| FFmpeg metadata | `ffmeta` | `FfMetadataChaptersParser`, `FfMetadataChaptersFormatter` | `;FFMETADATA1`, then `[CHAPTER]`, `TIMEBASE=1/1000`, `START=168000`, `END=260000`, `title=Hearing aids` |
| OGM chapters, the mkvmerge simple format | `ogm` | `OgmChaptersParser`, `OgmChaptersFormatter` | `CHAPTER02=00:02:48.000`, then `CHAPTER02NAME=Hearing aids` |
| WebVTT chapters | `vtt` | `WebVttParser`, `WebVttFormatter` | a WebVTT file with one cue per chapter |

```php
use SubtitleToolbox\Chapters\YouTubeChapters;
use SubtitleToolbox\Formatters\FfMetadataChaptersFormatter;
use SubtitleToolbox\Formatters\YouTubeChaptersFormatter;
use SubtitleToolbox\Parsers\PodcastChaptersParser;
use SubtitleToolbox\Parsers\YouTubeChaptersParser;

$chapters = (new PodcastChaptersParser(mediaDuration: 4980))->parse(file_get_contents('chapters.json'));
$text     = $chapters->format(YouTubeChaptersFormatter::class);       // for the video description
$meta     = $chapters->format(FfMetadataChaptersFormatter::class);    // ffmpeg -i in.mp4 -i meta.ffmeta -map_metadata 1 out.mp4
$broken   = YouTubeChapters::check($chapters);                        // [] when YouTube shows the chapters
$chapters = (new YouTubeChaptersParser())->parse($videoDescription);
```

- **End times**: a chapter ends where the next chapter starts. The last chapter ends at the parser argument `mediaDuration` in seconds. Without it, the last chapter ends at its own start. An `endTime` in the JSON and an `END` line in FFmpeg metadata win.
- **YouTube text**: the parser reads only the lines that start or end with a time such as `2:48`, `02:48`, `1:02:48` or `(2:48)`. So you can pass a whole video description. A line such as `Doors open at 18:30` also becomes a chapter. The formatter writes `m:ss` below one hour and `h:mm:ss` from one hour, in whole seconds.
- **YouTube rules**: `YouTubeChapters::check()` returns one entry per broken rule and chapter, for example `['rule' => YouTubeChapters::RULE_MIN_DURATION, 'chapterIndex' => 2, 'value' => 4.5, 'limit' => 10]`. The rules are `RULE_FIRST_CHAPTER_AT_ZERO`, `RULE_MIN_CHAPTERS` (3) and `RULE_MIN_DURATION` (10 s), from [YouTube Help](https://support.google.com/youtube/answer/9884579). The check skips a last chapter that ends at its own start, because its length is unknown.
- **Podcasting 2.0**: the `title` and `author` of the file become metadata. The `chapters` format data of the subtitle keeps `version` and the other fields. Each cue keeps `img`, `url`, `toc`, `location` and other fields in its `chapters` format data. The formatter writes `endTime` only where the end is not the start of the next chapter. For the last chapter, it writes `endTime` when the end is not its own start. It writes version `1.2.0` for chapters from another format.
- **FFmpeg metadata**: the global tags `title`, `author`, `artist`, `album` and `language` become metadata. The `ffmetadata` format data keeps all global tags and the `[STREAM]` sections. Each cue keeps its time base and its other tags. The formatter writes the same time base, or `1/1000` for chapters from another format. Without a `TIMEBASE` line, the parser reads `START` and `END` in nanoseconds, as FFmpeg does.
- **OGM chapters**: a `CHAPTERxx=` line needs 1 to 9 digits after `.` or `,`. A `CHAPTERxxNAME=` line must follow it. Otherwise the parser throws `ParsingException` with the line number.
- **Detection**: format detection finds Podcasting 2.0 JSON, FFmpeg metadata and OGM chapters. YouTube text has no signature, so pass `YouTubeChaptersParser::class`.
- **Command line tool**: `ogm`, `podcast` and `ytchapter` share `.txt` and `.json` with plain text and the library JSON. So pass `--from` or `--to`.
- **Lenient mode**: the chapter parsers ignore `setLenient()`.
