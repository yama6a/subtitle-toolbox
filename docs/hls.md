# HLS

HLS (HTTP Live Streaming) cuts a subtitle track into WebVTT files of 6 s each by default, the segments. An `.m3u8` playlist lists them. Each segment has an `X-TIMESTAMP-MAP` header. The header maps a WebVTT cue time to the 90 kHz MPEG-2 timestamp of the video.

```php
use SubtitleToolbox\Hls\HlsSegmentOptions;
use SubtitleToolbox\Hls\HlsWebVttJoiner;
use SubtitleToolbox\Hls\HlsWebVttSegmenter;
use SubtitleToolbox\Hls\TimestampMap;

$hls = HlsWebVttSegmenter::segment($subtitle);   // 6 s segments named sub0.vtt, sub1.vtt and on
$hls = HlsWebVttSegmenter::segment($subtitle, new HlsSegmentOptions(
    segmentDuration: 6,                 // seconds
    mpegts: 900000,                     // MPEG-2 timestamp at which subtitle time 0 plays
    local: 0,                           // WebVTT cue time in seconds that maps to mpegts
    fileNamePattern: 'sub%d.vtt',       // %d is the 0-based segment number
    mediaDuration: 20,                  // seconds the playlist covers, null for the end of the last cue
));
foreach ($hls->getSegments() as $name => $vtt) {   // 'sub0.vtt' => "WEBVTT\nX-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:900000\n\n..."
    file_put_contents("out/$name", $vtt);
}
$hls->getSegmentCount();                // 4 for 20 s and 6 s segments
file_put_contents('out/subs.m3u8', $hls->getPlaylist());

$subtitle = HlsWebVttJoiner::join([$vtt0, $vtt1, $vtt2, $vtt3]);    // cue times from the start of the stream
$subtitle = HlsWebVttJoiner::join($segments, streamStartPts: 126000);

$map = TimestampMap::fromHeader('X-TIMESTAMP-MAP=MPEGTS:181083,LOCAL:00:00:00.000');
$map->offset(126000);                   // about 0.612, the seconds to add to a cue time
```

The playlist for a 20 s subtitle with 6 s segments:

```
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:6
#EXT-X-MEDIA-SEQUENCE:0
#EXT-X-PLAYLIST-TYPE:VOD
#EXTINF:6.000,
sub0.vtt
#EXTINF:6.000,
sub1.vtt
#EXTINF:6.000,
sub2.vtt
#EXTINF:2.000,
sub3.vtt
#EXT-X-ENDLIST
```

## Segmenting
- **Cues across a boundary**: a cue goes into every segment that it overlaps, with its full start and end time. [RFC 8216 section 3.5](https://datatracker.ietf.org/doc/html/rfc8216#section-3.5) requires this. A cue without an identifier gets its number in the whole subtitle, so it has the same identifier in each segment.
- **Empty segments**: a segment without cues still has the header. Apple's [HLS authoring specification](https://developer.apple.com/documentation/http-live-streaming/hls-authoring-specification-for-apple-devices) requires a subtitle playlist for the whole content. Set `mediaDuration` to the video duration for that.
- **Cue times**: a cue at subtitle time `t` gets the WebVTT time `t + local`.
- **Playlist**: a VOD media playlist. Apple recommends 6 s segments.
- **Memory**: `getSegments()` and `getDurations()` are generators. They write each segment when the loop reads it. So 600,000 segments of a 1,000-hour subtitle need about 40 MB, most of it for the playlist. `iterator_to_array($hls->getSegments())` returns all segments as an array.
- **Segment files**: UTF-8 without BOM, LF line endings. The header text, other header lines, `STYLE` and `REGION` blocks of the subtitle go into each segment. The segmenter replaces an old `X-TIMESTAMP-MAP` line. It does not copy comments.

## Joining
- **Duplicates**: `join()` parses the segments in playlist order. It keeps one copy of a cue that repeats with the same times and text. Then `removeDuplicateCues()` joins a cue that a segmenter split at a boundary. That call also joins two cues with the same text in the source when they overlap or touch.
- **Stream start**: `join()` returns cue times from `streamStartPts`. Without it, the `MPEGTS` value of the first segment is the start. A segment without the header maps cue time 0 to `MPEGTS` 0. A cue time that becomes negative becomes 0.
- **Header order**: `TimestampMap::fromHeader()` reads `LOCAL` and `MPEGTS` in both orders.
- **Timestamp wrap**: MPEG-2 timestamps wrap after about 26.5 hours. `offset()` handles the wrap.
