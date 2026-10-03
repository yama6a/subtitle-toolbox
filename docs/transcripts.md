# Transcripts

Speech-to-text tools, YouTube and podcast apps use their own transcript formats. The parsers on this page turn them into cues, so you can write SubRip or WebVTT. All parsers read only. The podcast transcript formats and plain text can also be written.

## Whisper JSON
A speech-to-text tool based on OpenAI Whisper writes a JSON transcript.

```php
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::parse($openAiResponseBody);                          // detects WhisperJsonParser
$parser   = new WhisperJsonParser([WhisperJsonParser::OPTION_WORD_TIMESTAMPS => true]);
$subtitle = $parser->parse(file_get_contents('lecture.json'));
$subtitle->getCues()[0]->getText();                                          // '<00:00:00.000>The <00:00:00.240>beach <00:00:00.710>was <00:00:00.950>quiet.'
$subtitle->getCues()[0]->getFormatData('whisper')['avg_logprob'];            // -0.25
```

| Tool | Shape | Source |
|:--- |:--- |:--- |
| OpenAI API | `response_format=verbose_json`. `segments`, and `words` at the top level with `timestamp_granularities[]=word` | [API reference](https://platform.openai.com/docs/api-reference/audio/createTranscription) |
| openai-whisper | `--output_format json`. `segments`, with `words` per segment with `--word_timestamps True` | [`transcribe.py`](https://github.com/openai/whisper/blob/86098128c0b4f24f0e2aa2994de830614b474227/whisper/transcribe.py) |
| faster-whisper, whisper-ctranslate2 | `segments` with the fields of the `Segment` class | [`transcribe.py`](https://github.com/SYSTRAN/faster-whisper/blob/7b99be5376b41cd481dfc52e8caa00a497c3294e/faster_whisper/transcribe.py) |
| WhisperX | `segments` with `words` that have `score` and, after diarization, `speaker` | [`alignment.py`](https://github.com/m-bain/whisperX/blob/771b4a14a9486f8fd5aef18ef49e35d639523dd3/whisperx/alignment.py) |
| whisper.cpp | `-oj`: `transcription` with `offsets` in milliseconds. `-ojf` adds `tokens` | [`cli.cpp`](https://github.com/ggml-org/whisper.cpp/blob/60c0be6ac8fa71b1a2ae2dd938a31a34a508e774/examples/cli/cli.cpp) |

- **Cues**: one cue per segment. The parser trims the text and skips segments without text. A long segment stays one cue. [`splitLongCues()`](editing.md#long-cues) breaks it up.
- **Word timestamps**: off by default. With `OPTION_WORD_TIMESTAMPS`, each word that has a start time and occurs in the segment text gets a core word timestamp before it. The parser skips the other words. The OpenAI API lists the words at the top level. A word then goes to the segment that holds the middle of the word.
- **Speakers**: off by default. `OPTION_SPEAKER_VOICES` writes the segment `speaker` as a `<v>` tag. See [text.md](text.md#speakers).
- **Language**: the `language` metadata. A name such as `english` becomes `en`. A code such as `en` stays.
- **Format data**: the subtitle keeps the top-level fields except the segments, words and text, for example `duration`. Each cue keeps the fields of its segment except the times and the text, for example `avg_logprob`, `no_speech_prob`, `words` and `speaker`.
- **Errors**: JSON without a `segments` or `transcription` list throws `ParsingException`. A response with only `words` or `text` has no cue times, so it throws too.

## Cloud speech-to-text JSON
Amazon Transcribe, Deepgram, AssemblyAI and Google Cloud Speech-to-Text return a JSON transcript. One parser per service turns it into cues.

```php
use SubtitleToolbox\Parsers\DeepgramParser;

$subtitle = Subtitle::parse($transcribeJson);                                // detects AwsTranscribeParser
$parser   = new DeepgramParser([
    DeepgramParser::OPTION_WORD_TIMESTAMPS => true,
    DeepgramParser::OPTION_SPEAKER_VOICES  => true,
]);
$subtitle = $parser->parse($deepgramResponseBody);
$subtitle->getCues()[2]->getText();                                          // '<v 0><00:00:06.500>Thank <00:00:06.800>you.'
$subtitle->getCues()[2]->getFormatData('deepgram')['confidence'];            // 0.9637655
```

| Service | Parser, format data key | Cues |
|:--- |:--- |:--- |
| [Amazon Transcribe](https://docs.aws.amazon.com/transcribe/latest/dg/how-input.html#how-output) | `AwsTranscribeParser`, `aws-transcribe` | one per `results.audio_segments` entry, else grouped from `results.items` |
| [Deepgram](https://developers.deepgram.com/docs/pre-recorded-audio) | `DeepgramParser`, `deepgram` | one per `results.utterances` entry, else one per paragraph sentence, else grouped from the words |
| [AssemblyAI](https://www.assemblyai.com/docs/api-reference/transcripts/get) | `AssemblyAiParser`, `assemblyai` | one per `utterances` entry, else grouped from `words` |
| [Google Cloud Speech-to-Text](https://cloud.google.com/speech-to-text/docs/async-time-offsets) | `GoogleSpeechParser`, `google-speech` | one per result, else grouped from the words of the last result |

- **Word grouping**: a cue ends after a word that ends a sentence with `.`, `?`, `!` or their CJK forms. It also ends before a pause of 1 s or more, before a word that makes it longer than 84 characters, and where the speaker changes.
- **Long cues**: an audio segment, utterance or result stays one cue. [`splitLongCues()`](editing.md#long-cues) breaks it up. With `OPTION_WORD_TIMESTAMPS`, `resegmentByWords()` regroups the words with other limits.
- **Word timestamps**: off by default. With `OPTION_WORD_TIMESTAMPS`, each word gets a core word timestamp before it.
- **Speakers**: off by default. `OPTION_SPEAKER_VOICES` writes the speaker label of the service as a `<v>` tag, for example `<v spk_0>`, `<v 0>`, `<v A>` or `<v 1>`. [`SpeakerLabels::rename()`](text.md#speakers) gives them names.
- **Amazon Transcribe**: the language comes from `results.language_code`.
- **Deepgram**: the parser reads every channel and sorts the cues by time. The language comes from `detected_language` of the first channel.
- **AssemblyAI**: the language `en_us` becomes `en-US`.
- **Google**: the parser reads the V1 and V2 field names, and a long-running operation from `operations.get` through its `response`.
- **Format data**: the subtitle keeps the top-level fields except the transcript text and the lists of words and segments. Each cue keeps the fields of its segment, utterance or result except the times and the text. Its words with their confidence are in `items` for Amazon Transcribe and in `words` for the other services.
- **Errors**: each parser throws `ParsingException` for JSON without the list it needs: `results.items` for Amazon Transcribe, `results.channels` for Deepgram, `words` or `utterances` for AssemblyAI and `results` for Google.

## YouTube timed text
yt-dlp and youtube-transcript-api download YouTube captions as json3, srv3 or the older transcript XML. json3 and srv3 keep the time of each word of automatic captions. WebVTT downloads lose it.

```php
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;

$subtitle = Subtitle::parse(file_get_contents('video.en.json3'));          // detects YouTubeTimedTextParser
$parser   = new YouTubeTimedTextParser([YouTubeTimedTextParser::OPTION_WORD_TIMESTAMPS => true]);
$subtitle = $parser->parse(file_get_contents('video.en.srv3'));
$subtitle->getCues()[0]->getText();                                          // '<00:00:01.200>Hello <00:00:01.600>world'
$subtitle->getFormatData('youtube')['format'];                               // 'srv3'
```

| Format | Shape |
|:--- |:--- |
| json3 | `{"events": [{"tStartMs": 1200, "dDurationMs": 2300, "segs": [{"utf8": "Hello"}, {"utf8": " world", "tOffsetMs": 400}]}]}` |
| srv3 | `<timedtext format="3"><body><p t="1200" d="2300">Hello<s t="400"> world</s></p></body></timedtext>` |
| srv2 | `<timedtext><text t="1200" d="2300">Hello world</text></timedtext>` |
| srv1 and transcript XML | `<transcript><text start="1.2" dur="2.3">Hello world</text></transcript>` |

- **Automatic captions**: the parser skips the events that only add a line break. A cue in a window ends where the next cue of the same window starts, so the rolling cues do not stack.
- **Word timestamps**: off by default. With `OPTION_WORD_TIMESTAMPS`, each segment of a cue gets a core word timestamp, but only when at least one segment of the cue has a time. srv1 and srv2 have no word times.
- **Alignment**: from the anchor point of the window position of a cue. Anchor point 0 is top left and becomes alignment 7. A cue without its own window position, such as an automatic caption, has no alignment.
- **Pens**: the pen colour becomes `<font color>`. Bold, italic and underline become `<b>`, `<i>` and `<u>`.
- **Format data**: the subtitle keeps the `format` name, and the head elements and windows of the file. Each cue keeps the other fields of its event or `<p>`, and the other fields of its segments in `segments`.

## Podcast transcripts
A podcast feed links a transcript per episode with the `<podcast:transcript>` tag. Apple Podcasts, Podverse and Fountain read it. The [Podcasting 2.0 transcript spec](https://github.com/Podcastindex-org/podcast-namespace/blob/main/docs/examples/transcripts/transcripts.md) allows SubRip, WebVTT, a JSON format and an HTML format. The library reads and writes all four.

```json
{"version": "1.0.0", "segments": [
  {"speaker": "Anna", "startTime": 0.5, "endTime": 0.75, "body": "The"},
  {"speaker": "Anna", "startTime": 1, "endTime": 1.25, "body": "bakery"}
]}
```

```html
<cite>Anna:</cite>
<time>0:00</time>
<p>The bakery opens at seven.</p>
```

```php
use SubtitleToolbox\Formatters\HtmlTranscriptFormatter;
use SubtitleToolbox\Formatters\PodcastTranscriptFormatter;
use SubtitleToolbox\Parsers\PodcastTranscriptParser;

$subtitle = (new WhisperJsonParser([WhisperJsonParser::OPTION_WORD_TIMESTAMPS => true]))->parse($whisperJson);
$json     = $subtitle->format(PodcastTranscriptFormatter::class, [PodcastTranscriptFormatter::OPTION_WORD_SEGMENTS => true]);
$html     = $subtitle->format(HtmlTranscriptFormatter::class);
$subtitle = Subtitle::parse(file_get_contents('episode.json'));                  // detects PodcastTranscriptParser
$subtitle = (new PodcastTranscriptParser([PodcastTranscriptParser::OPTION_KEEP_SEGMENTS => true]))->parse($json);
```

| Class | Option | Effect |
|:--- |:--- |:--- |
| `PodcastTranscriptParser` | `OPTION_KEEP_SEGMENTS` | one cue per segment. By default, segments of one word join into a cue |
| `PodcastTranscriptParser` | `OPTION_WORD_TIMESTAMPS` | a core word timestamp before each word of a joined cue |
| `PodcastTranscriptFormatter` | `OPTION_WORD_SEGMENTS` | one segment per core word timestamp, for the word highlight of the apps. By default, one segment per cue |
| `PodcastTranscriptFormatter` | `OPTION_PRETTY_PRINT` | indents with 4 spaces and ends with a newline |
| `HtmlTranscriptFormatter` | `OPTION_PARAGRAPH_GAP` | the gap in seconds that starts a new paragraph, 2.0 by default |

- **Speakers**: the `speaker` of a segment and the name in `<cite>` become `<v Name>`, and back. A cue with two `<v>` speakers gives one segment per speaker, both with the times of the cue.
- **Joined words**: the parser joins a segment of one word with the next segment of the same speaker. It stops after a word that ends with `.`, `?`, `!` or the ellipsis U+2026. A segment with a space in its body stays one cue.
- **Word segments**: a word ends where the next word of its cue starts. The last word ends with the cue. So a round trip keeps the start of each word, not its end.
- **No end time**: a segment without `endTime` and an HTML paragraph end at the next later start. The last one lasts 10 s, or the last constructor argument of the parser.
- **HTML input**: each `<time>` starts a cue. The cue holds the `<p>` elements up to the next `<time>` or `<cite>`, one line per `<p>` and `<br>`. A `<cite>` names only the next cue. The parser strips other tags and reads times such as `0:09`, `12:05` and `1:02:03.5`.
- **HTML output**: a new paragraph starts at a speaker change or a gap. The formatter writes `<cite>` only for a paragraph with a speaker, times such as `0:09` and `1:02:03`, and the text without tags.
- **Format data**: the JSON parser keeps the top-level fields except `segments` in `getFormatData('podcast')`, for example `version`. A cue of one segment keeps the other fields of the segment. The formatter writes them back, and `"version": "1.0.0"` when there is none.
- **Errors**: a segment without a numeric `startTime` throws `ParsingException`. So does a `speaker`, `endTime` or `body` of the wrong type, a `<p>` without a `<time>` before it, and a bad time. A segment without `body` gives no cue.
- **Command line tool**: the format names are `podcast-transcript` and `html`. `.json` stays the library JSON, so pass `--to podcast-transcript`.

## Plain text
`PlainTextFormatter` writes a transcript. It strips all tags and decodes the entities. A gap of 2 s or more between two cues starts a new paragraph:

```
Hello world. Where are you going?

Home.
```

```php
use SubtitleToolbox\Formatters\PlainTextFormatter;

$text = $subtitle->format(PlainTextFormatter::class, [PlainTextFormatter::OPTION_WITH_TIMES => true]);
```

| Option | Default | Effect |
|:--- |:--- |:--- |
| `OPTION_JOIN_LINES` | `true` | joins the lines of a cue with a space. `false` writes each line on its own line |
| `OPTION_JOIN_CUES` | `true` | joins the cues of a paragraph with a space. `false` writes each cue on its own line |
| `OPTION_PARAGRAPH_GAP` | `2.0` | the gap in seconds that starts a new paragraph. `INF` writes one paragraph |
| `OPTION_WITH_TIMES` | `false` | writes the start of the paragraph as `[00:01:23] ` before it |

- **Gap**: the start of a cue minus the latest end of the earlier cues.
- **Cues without text**: the formatter skips them. Image cues without text need `OPTION_SKIP_IMAGE_CUES`, as in all text formatters.
- **No parser**: the library cannot read plain text.
