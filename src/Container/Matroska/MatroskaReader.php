<?php

namespace SubtitleToolbox\Container\Matroska;

use Generator;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Streaming\Streams;
use SubtitleToolbox\Subtitle;

/**
 * Reads the subtitle tracks of Matroska (.mkv, .mka, .mks) and WebM files. It skips video and audio data with fseek().
 *
 * Elements: https://www.matroska.org/technical/elements.html
 * Subtitle codecs: https://www.matroska.org/technical/subtitles.html
 * EBML: https://datatracker.ietf.org/doc/html/rfc8794
 */
final class MatroskaReader
{
    public const CODEC_SUBRIP = "S_TEXT/UTF8";
    public const CODEC_ASS    = "S_TEXT/ASS";
    public const CODEC_SSA    = "S_TEXT/SSA";
    public const CODEC_WEBVTT = "S_TEXT/WEBVTT";
    public const CODEC_PGS    = "S_HDMV/PGS";

    public const CODECS = [self::CODEC_SUBRIP, self::CODEC_ASS, self::CODEC_SSA, self::CODEC_WEBVTT, self::CODEC_PGS];

    public const DEFAULT_LAST_CUE_DURATION = 5.0;

    private const ID_EBML             = 0x1A45DFA3;
    private const ID_DOC_TYPE         = 0x4282;
    private const ID_SEGMENT          = 0x18538067;
    private const ID_SEEK_HEAD        = 0x114D9B74;
    private const ID_SEEK             = 0x4DBB;
    private const ID_SEEK_ID          = 0x53AB;
    private const ID_SEEK_POSITION    = 0x53AC;
    private const ID_INFO             = 0x1549A966;
    private const ID_TIMESTAMP_SCALE  = 0x2AD7B1;
    private const ID_TRACKS           = 0x1654AE6B;
    private const ID_TRACK_ENTRY      = 0xAE;
    private const ID_TRACK_NUMBER     = 0xD7;
    private const ID_TRACK_TYPE       = 0x83;
    private const ID_CODEC_ID         = 0x86;
    private const ID_CODEC_PRIVATE    = 0x63A2;
    private const ID_LANGUAGE         = 0x22B59C;
    private const ID_LANGUAGE_BCP47   = 0x22B59D;
    private const ID_NAME             = 0x536E;
    private const ID_FLAG_DEFAULT     = 0x88;
    private const ID_FLAG_FORCED      = 0x55AA;
    private const ID_DEFAULT_DURATION = 0x23E383;
    private const ID_ENCODINGS        = 0x6D80;
    private const ID_ENCODING         = 0x6240;
    private const ID_ENCODING_ORDER   = 0x5031;
    private const ID_ENCODING_SCOPE   = 0x5032;
    private const ID_ENCODING_TYPE    = 0x5033;
    private const ID_COMPRESSION      = 0x5034;
    private const ID_COMP_ALGO        = 0x4254;
    private const ID_COMP_SETTINGS    = 0x4255;
    private const ID_CLUSTER          = 0x1F43B675;
    private const ID_TIMESTAMP        = 0xE7;
    private const ID_SIMPLE_BLOCK     = 0xA3;
    private const ID_BLOCK_GROUP      = 0xA0;
    private const ID_BLOCK            = 0xA1;
    private const ID_BLOCK_DURATION   = 0x9B;
    private const ID_BLOCK_ADDITIONS  = 0x75A1;
    private const ID_BLOCK_MORE       = 0xA6;
    private const ID_BLOCK_ADD_ID     = 0xEE;
    private const ID_BLOCK_ADDITIONAL = 0xA5;

    private const TRACK_TYPE_SUBTITLE     = 0x11;
    private const DEFAULT_TIMESTAMP_SCALE = 1000000;
    private const SCOPE_FRAMES            = 1;
    private const SCOPE_CODEC_PRIVATE     = 2;
    private const ALGO_ZLIB               = 0;
    private const ALGO_HEADER_STRIPPING   = 3;

    // The WebVTT BlockAdditional of a cue has the BlockAddID 1, the default value.
    private const WEBVTT_ADD_ID = 1;

    private const ASS_FIELDS = [
        "layer" => 1, "marked" => 1, "style" => 2, "name" => 3, "actor" => 3,
        "marginl" => 4, "marginr" => 5, "marginv" => 6, "effect" => 7, "text" => 8,
    ];

    /** @var resource */
    private $stream;

    private bool $ownsStream;

    private EbmlReader $ebml;

    private int $segmentEnd;

    private int $firstCluster;

    private int $timestampScale = self::DEFAULT_TIMESTAMP_SCALE;

    /** @var array<int, MatroskaTrack> */
    private array $tracks = [];

    /** @var array<int, array{codecPrivate: string, defaultDuration: ?int, encodings: list<array{scope: int, type: int, algo: int, settings: string}>}> */
    private array $trackData = [];


    /**
     * @param resource $stream
     */
    private function __construct($stream, bool $ownsStream)
    {
        $this->stream     = $stream;
        $this->ownsStream = $ownsStream;
        $this->ebml       = new EbmlReader($stream);
        $this->readHeaders();
    }


    public function __destruct()
    {
        if ($this->ownsStream && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }


    /**
     * Opens a file path or a seekable stream resource and reads the track list. A stream stays open after the reader ends.
     *
     * @param string|resource $file
     */
    public static function open($file): self
    {
        $stream = Streams::open($file, "rb");
        if (!stream_get_meta_data($stream)["seekable"]) {
            throw new InvalidArgumentException("The stream must be seekable.");
        }

        return new self($stream, !is_resource($file));
    }


    /**
     * @return list<MatroskaTrack>
     */
    public function getSubtitleTracks(): array
    {
        return array_values($this->tracks);
    }


    /**
     * Reads all blocks of the subtitle track with the TrackNumber $trackNumber.
     */
    public function extract(int $trackNumber): Subtitle
    {
        $track = $this->tracks[$trackNumber] ?? null;
        if ($track === null) {
            throw new InvalidArgumentException("The file has no subtitle track with the number $trackNumber.");
        }
        if (!in_array($track->codecId, self::CODECS, true)) {
            throw new ParsingException("Track $trackNumber has the codec $track->codecId. The reader extracts only " .
                                       implode(", ", self::CODECS) . ".");
        }

        $data         = $this->trackData[$trackNumber];
        $codecPrivate = $this->decode($trackNumber, $data["codecPrivate"], self::SCOPE_CODEC_PRIVATE);
        $blocks       = $this->readBlocks($trackNumber);

        $subtitle = match ($track->codecId) {
            self::CODEC_PGS    => (new PgsParser())->parse($this->pgsStream($blocks)),
            self::CODEC_WEBVTT => (new WebVttParser())->parse($this->webVttFile($codecPrivate, $this->withEnds($blocks, $data))),
            self::CODEC_SUBRIP => (new SubRipParser())->parse($this->subRipFile($this->withEnds($blocks, $data))),
            default            => (new AssParser())->parse($this->assFile($track, $codecPrivate, $this->withEnds($blocks, $data))),
        };

        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $track->language);
        if ($track->forced) {
            foreach ($subtitle->getCues() as $cue) {
                $cue->setForced(true);
            }
        }

        return $subtitle;
    }


    private function readHeaders(): void
    {
        $header  = $this->ebml->readElementHeader();
        $docType = $header !== null && $header["id"] === self::ID_EBML ? $this->readDocType($header) : null;
        if (!in_array($docType, ["matroska", "webm"], true) || ($segment = $this->findSegment()) === null) {
            throw new ParsingException("The file is not a Matroska or WebM file.");
        }

        $this->segmentEnd   = $segment["size"] === EbmlReader::UNKNOWN_SIZE ? PHP_INT_MAX : $segment["offset"] + $segment["size"];
        $this->firstCluster = $this->segmentEnd;

        $positions = [];
        $tracks    = null;
        $info      = null;
        $this->ebml->seek($segment["offset"]);
        while (($position = $this->ebml->tell()) < $this->segmentEnd && ($element = $this->ebml->readElementHeader()) !== null) {
            if ($element["id"] === self::ID_CLUSTER) {
                $this->firstCluster = $position;
                break;
            }

            match ($element["id"]) {
                self::ID_SEEK_HEAD => $positions += $this->readSeekHead($element),
                self::ID_INFO      => $info = $element,
                self::ID_TRACKS    => $tracks = $element,
                default            => null,
            };
            $this->skip($element);
        }

        $info   ??= $this->elementAt($segment["offset"], $positions[self::ID_INFO] ?? null);
        $tracks ??= $this->elementAt($segment["offset"], $positions[self::ID_TRACKS] ?? null);
        if ($tracks === null) {
            throw new ParsingException("The file has no Tracks element.");
        }

        if ($info !== null) {
            $this->readInfo($info);
        }
        $this->readTracks($tracks);
    }


    private function readDocType(array $header): ?string
    {
        foreach ($this->children($header) as $child) {
            if ($child["id"] === self::ID_DOC_TYPE) {
                return $this->ebml->readString($child["size"]);
            }
        }

        return null;
    }


    private function findSegment(): ?array
    {
        while (($element = $this->ebml->readElementHeader()) !== null) {
            if ($element["id"] === self::ID_SEGMENT) {
                return $element;
            }
            $this->skip($element);
        }

        return null;
    }


    /**
     * @return array<int, int> element ID => position relative to the segment data
     */
    private function readSeekHead(array $seekHead): array
    {
        $positions = [];
        foreach ($this->children($seekHead) as $seek) {
            if ($seek["id"] !== self::ID_SEEK) {
                continue;
            }

            $id       = null;
            $position = null;
            foreach ($this->children($seek) as $child) {
                match ($child["id"]) {
                    self::ID_SEEK_ID       => $id = $this->ebml->readUnsigned($child["size"]),
                    self::ID_SEEK_POSITION => $position = $this->ebml->readUnsigned($child["size"]),
                    default                => null,
                };
            }
            if ($id !== null && $position !== null) {
                $positions[$id] ??= $position;
            }
        }

        return $positions;
    }


    private function elementAt(int $segmentOffset, ?int $position): ?array
    {
        if ($position === null) {
            return null;
        }

        $this->ebml->seek($segmentOffset + $position);

        return $this->ebml->readElementHeader();
    }


    private function readInfo(array $info): void
    {
        foreach ($this->children($info) as $child) {
            if ($child["id"] === self::ID_TIMESTAMP_SCALE) {
                $this->timestampScale = $this->ebml->readUnsigned($child["size"]);
            }
        }
    }


    private function readTracks(array $tracks): void
    {
        foreach ($this->children($tracks) as $entry) {
            if ($entry["id"] !== self::ID_TRACK_ENTRY) {
                continue;
            }

            $fields = ["default" => true, "forced" => false, "codecPrivate" => "", "defaultDuration" => null, "encodings" => []];
            foreach ($this->children($entry) as $child) {
                $size = $child["size"];
                match ($child["id"]) {
                    self::ID_TRACK_NUMBER     => $fields["number"] = $this->ebml->readUnsigned($size),
                    self::ID_TRACK_TYPE       => $fields["type"] = $this->ebml->readUnsigned($size),
                    self::ID_CODEC_ID         => $fields["codecId"] = $this->ebml->readString($size),
                    self::ID_CODEC_PRIVATE    => $fields["codecPrivate"] = $this->ebml->readBytes($size),
                    self::ID_LANGUAGE         => $fields["language"] = $this->ebml->readString($size),
                    self::ID_LANGUAGE_BCP47   => $fields["bcp47"] = $this->ebml->readString($size),
                    self::ID_NAME             => $fields["name"] = $this->ebml->readString($size),
                    self::ID_FLAG_DEFAULT     => $fields["default"] = $this->ebml->readUnsigned($size) !== 0,
                    self::ID_FLAG_FORCED      => $fields["forced"] = $this->ebml->readUnsigned($size) !== 0,
                    self::ID_DEFAULT_DURATION => $fields["defaultDuration"] = $this->ebml->readUnsigned($size),
                    self::ID_ENCODINGS        => $fields["encodings"] = $this->readEncodings($child),
                    default                   => null,
                };
            }

            if (($fields["type"] ?? null) !== self::TRACK_TYPE_SUBTITLE || !isset($fields["number"])) {
                continue;
            }

            $number                   = $fields["number"];
            $this->tracks[$number]    = new MatroskaTrack(
                $number,
                $fields["codecId"] ?? "",
                $fields["bcp47"] ?? $fields["language"] ?? "eng",
                $fields["name"] ?? null,
                $fields["default"],
                $fields["forced"],
            );
            $this->trackData[$number] = [
                "codecPrivate"    => $fields["codecPrivate"],
                "defaultDuration" => $fields["defaultDuration"],
                "encodings"       => $fields["encodings"],
            ];
        }
    }


    /**
     * @return list<array{scope: int, type: int, algo: int, settings: string}> in decoding order, the highest ContentEncodingOrder first
     */
    private function readEncodings(array $encodings): array
    {
        $list = [];
        foreach ($this->children($encodings) as $encoding) {
            if ($encoding["id"] !== self::ID_ENCODING) {
                continue;
            }

            $fields = ["order" => 0, "scope" => self::SCOPE_FRAMES, "type" => 0, "algo" => self::ALGO_ZLIB, "settings" => ""];
            foreach ($this->children($encoding) as $child) {
                match ($child["id"]) {
                    self::ID_ENCODING_ORDER => $fields["order"] = $this->ebml->readUnsigned($child["size"]),
                    self::ID_ENCODING_SCOPE => $fields["scope"] = $this->ebml->readUnsigned($child["size"]),
                    self::ID_ENCODING_TYPE  => $fields["type"] = $this->ebml->readUnsigned($child["size"]),
                    self::ID_COMPRESSION    => $fields = $this->readCompression($child) + $fields,
                    default                 => null,
                };
            }
            $list[] = $fields;
        }
        usort($list, fn (array $a, array $b): int => $b["order"] <=> $a["order"]);

        return array_map(fn (array $fields): array => array_diff_key($fields, ["order" => true]), $list);
    }


    /**
     * @return array{algo?: int, settings?: string}
     */
    private function readCompression(array $compression): array
    {
        $fields = [];
        foreach ($this->children($compression) as $child) {
            match ($child["id"]) {
                self::ID_COMP_ALGO     => $fields["algo"] = $this->ebml->readUnsigned($child["size"]),
                self::ID_COMP_SETTINGS => $fields["settings"] = $this->ebml->readBytes($child["size"]),
                default                => null,
            };
        }

        return $fields;
    }


    /**
     * Walks all clusters. Clusters of unknown size, as live recordings write them, end at the next cluster.
     *
     * @return list<array{start: int, duration: ?int, data: string, additional: ?string}> times in TimestampScale ticks
     */
    private function readBlocks(int $trackNumber): array
    {
        $blocks      = [];
        $clusterTime = 0;
        $this->ebml->seek($this->firstCluster);
        while ($this->ebml->tell() < $this->segmentEnd && ($element = $this->ebml->readElementHeader()) !== null) {
            $block = null;
            match ($element["id"]) {
                self::ID_CLUSTER      => $clusterTime = 0,
                self::ID_TIMESTAMP    => $clusterTime = $this->ebml->readUnsigned($element["size"]),
                self::ID_SIMPLE_BLOCK => $block = $this->readBlock($element, $trackNumber),
                self::ID_BLOCK_GROUP  => $block = $this->readBlockGroup($element, $trackNumber),
                default               => null,
            };
            if ($element["id"] !== self::ID_CLUSTER) {
                $this->skip($element);
            }

            if ($block !== null) {
                $block["start"] += $clusterTime;
                $block["data"]   = $this->decode($trackNumber, $block["data"], self::SCOPE_FRAMES);
                $blocks[]        = $block;
            }
        }

        return $blocks;
    }


    /**
     * @return array{start: int, duration: ?int, data: string, additional: ?string}|null null for a block of another track
     */
    private function readBlockGroup(array $group, int $trackNumber): ?array
    {
        $block      = null;
        $duration   = null;
        $additional = null;
        foreach ($this->children($group) as $child) {
            if ($child["id"] === self::ID_BLOCK) {
                $block = $this->readBlock($child, $trackNumber);
                if ($block === null) {
                    return null;
                }
            } elseif ($child["id"] === self::ID_BLOCK_DURATION) {
                $duration = $this->ebml->readUnsigned($child["size"]);
            } elseif ($child["id"] === self::ID_BLOCK_ADDITIONS) {
                $additional = $this->readBlockAdditions($child);
            }
        }

        return $block === null ? null : ["duration" => $duration, "additional" => $additional] + $block;
    }


    private function readBlockAdditions(array $additions): ?string
    {
        foreach ($this->children($additions) as $more) {
            if ($more["id"] !== self::ID_BLOCK_MORE) {
                continue;
            }

            $id   = self::WEBVTT_ADD_ID;
            $data = null;
            foreach ($this->children($more) as $child) {
                match ($child["id"]) {
                    self::ID_BLOCK_ADD_ID     => $id = $this->ebml->readUnsigned($child["size"]),
                    self::ID_BLOCK_ADDITIONAL => $data = $this->ebml->readBytes($child["size"]),
                    default                   => null,
                };
            }
            if ($id === self::WEBVTT_ADD_ID && $data !== null) {
                return $data;
            }
        }

        return null;
    }


    /**
     * Reads the track number, the relative timestamp and the flags of a SimpleBlock or Block, and the data of the wanted track.
     *
     * @return array{start: int, duration: null, data: string, additional: null}|null
     */
    private function readBlock(array $block, int $trackNumber): ?array
    {
        $header = $this->ebml->readBytes(min($block["size"], 11));
        $track  = EbmlReader::readVint($header, 0, false);
        if ($track === null || strlen($header) < $track[1] + 3) {
            $offset = $block["offset"];
            throw new ParsingException("The block header at byte $offset is not valid.");
        }
        if ($track[0] !== $trackNumber) {
            return null;
        }

        ["time" => $time, "flags" => $flags] = unpack("ntime/Cflags", $header, $track[1]);
        if (($flags & 0x06) !== 0) {
            throw new ParsingException("A block of track $trackNumber uses lacing, which subtitle tracks do not use.");
        }

        $this->ebml->seek($block["offset"] + $track[1] + 3);

        return [
            "start"      => $time >= 0x8000 ? $time - 0x10000 : $time,
            "duration"   => null,
            "data"       => $this->ebml->readBytes($block["size"] - $track[1] - 3),
            "additional" => null,
        ];
    }


    private function decode(int $trackNumber, string $data, int $scope): string
    {
        foreach ($this->trackData[$trackNumber]["encodings"] as $encoding) {
            if (($encoding["scope"] & $scope) === 0) {
                continue;
            }
            if ($encoding["type"] !== 0 || !in_array($encoding["algo"], [self::ALGO_ZLIB, self::ALGO_HEADER_STRIPPING], true)) {
                throw new ParsingException("Track $trackNumber uses encryption or a compression other than zlib and header stripping.");
            }

            if ($encoding["algo"] === self::ALGO_HEADER_STRIPPING) {
                $data = $encoding["settings"] . $data;
                continue;
            }

            // gzuncompress() warns before it returns false. The exception reports the failure.
            $inflated = function_exists("gzuncompress") ? @gzuncompress($data) : false;
            if ($inflated === false) {
                throw new ParsingException("The zlib data of track $trackNumber cannot be decompressed.");
            }
            $data = $inflated;
        }

        return $data;
    }


    /**
     * Converts the block times to milliseconds and sets the end of each block. A block without BlockDuration and
     * DefaultDuration ends at the start of the next block, the last one after DEFAULT_LAST_CUE_DURATION.
     *
     * @return list<array{start: int, end: int, data: string, additional: ?string}>
     */
    private function withEnds(array $blocks, array $trackData): array
    {
        $cues = [];
        foreach ($blocks as $index => $block) {
            $startTime = $block["start"] * $this->timestampScale;
            $start     = $this->milliseconds($startTime);
            $end       = match (true) {
                $block["duration"] !== null            => $this->milliseconds($startTime + $block["duration"] * $this->timestampScale),
                $trackData["defaultDuration"] !== null => $this->milliseconds($startTime + $trackData["defaultDuration"]),
                isset($blocks[$index + 1])             => $this->milliseconds($blocks[$index + 1]["start"] * $this->timestampScale),
                default                                => $start + (int) (self::DEFAULT_LAST_CUE_DURATION * 1000),
            };

            $cues[] = ["start" => $start, "end" => $end, "data" => $block["data"], "additional" => $block["additional"]];
        }

        return $cues;
    }


    private function subRipFile(array $cues): string
    {
        $file = "";
        foreach ($cues as $index => $cue) {
            $file .= ($index + 1) . "\n" . $this->time($cue["start"], ",", true) . " --> " . $this->time($cue["end"], ",", true) .
                     "\n" . $this->cueText($cue["data"]) . "\n\n";
        }

        return $file;
    }


    /**
     * Rebuilds the Dialogue lines from the ReadOrder, Layer, Style, Name, MarginL, MarginR, MarginV, Effect and Text
     * fields of each block, in the order of the Format line of the header, as mkvextract does.
     */
    private function assFile(MatroskaTrack $track, string $codecPrivate, array $cues): string
    {
        $header = rtrim(StringHelpers::normalizeEOLs(StringHelpers::removeUtf8Bom($codecPrivate))) . "\n";
        $format = $track->codecId === self::CODEC_SSA ? AssParser::SSA_EVENT_FORMAT : AssParser::ASS_EVENT_FORMAT;
        if (!preg_match('/^\[Events\][ \t]*$/mi', $header)) {
            $header .= "\n[Events]\nFormat: " . implode(", ", $format) . "\n";
        } elseif (preg_match('/^\[Events\][ \t]*\n(?:(?!\[).*\n)*?Format:(.*)$/mi', $header, $matches)) {
            $format = array_map("trim", explode(",", $matches[1]));
        }

        $events = [];
        foreach ($cues as $cue) {
            $fields = array_pad(explode(",", $cue["data"], 9), 9, "");
            $values = [];
            foreach ($format as $name) {
                $key      = strtolower($name);
                $values[] = match ($key) {
                    "start"  => $this->time($cue["start"], ".", false),
                    "end"    => $this->time($cue["end"], ".", false),
                    "marked" => str_starts_with($fields[1], "Marked=") ? $fields[1] : "Marked=" . ($fields[1] === "" ? "0" : $fields[1]),
                    "text"   => str_replace("\n", "\\N", StringHelpers::normalizeEOLs($fields[8])),
                    default  => $fields[self::ASS_FIELDS[$key] ?? -1] ?? "",
                };
            }
            $events[] = ["order" => (int) $fields[0], "line" => "Dialogue: " . implode(",", $values)];
        }
        usort($events, fn (array $a, array $b): int => $a["order"] <=> $b["order"]);

        return $header . implode("", array_map(fn (array $event): string => $event["line"] . "\n", $events));
    }


    /**
     * Rebuilds the cue blocks from the block text and the BlockAdditional, which holds the cue settings,
     * the cue identifier and the comments before the cue.
     */
    private function webVttFile(string $codecPrivate, array $cues): string
    {
        $file = $codecPrivate === "" ? "WEBVTT" : rtrim(StringHelpers::normalizeEOLs($codecPrivate));
        foreach ($cues as $cue) {
            [$settings, $identifier, $comments] = array_pad(explode("\n", StringHelpers::normalizeEOLs($cue["additional"] ?? ""), 3), 3, "");

            $file .= "\n\n";
            if (trim($comments) !== "") {
                $file .= rtrim($comments) . "\n\n";
            }
            if ($identifier !== "") {
                $file .= "$identifier\n";
            }

            $text  = preg_replace_callback(
                '/<(?:(\d+):)?(\d{2}):(\d{2})\.(\d{3})>/',
                fn (array $m): string => "<" . $this->time($cue["start"] + ((int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3]) * 1000 + (int) $m[4], ".", true) . ">",
                $this->cueText($cue["data"]),
            );
            $file .= $this->time($cue["start"], ".", true) . " --> " . $this->time($cue["end"], ".", true) .
                     ($settings === "" ? "" : " $settings") . "\n$text";
        }

        return $file . "\n";
    }


    /**
     * Puts the PG magic bytes, the PTS from the block timestamp and a DTS of 0 before each segment of each block.
     */
    private function pgsStream(array $blocks): string
    {
        $stream = "";
        foreach ($blocks as $block) {
            $pts    = intdiv($block["start"] * $this->timestampScale * 9, 100000) & 0xFFFFFFFF;
            $data   = $block["data"];
            $offset = 0;
            while ($offset < strlen($data)) {
                $size    = strlen($data) - $offset >= 3 ? unpack("n", $data, $offset + 1)[1] : 0;
                $stream .= "PG" . pack("NN", $pts, 0) . substr($data, $offset, 3 + $size);
                $offset += 3 + $size;
            }
        }

        return $stream;
    }


    /**
     * Removes the empty lines that would end the cue in a text file.
     */
    private function cueText(string $data): string
    {
        return preg_replace('/\n(?:[ \t]*\n)+/', "\n", trim(StringHelpers::normalizeEOLs($data), "\n"));
    }


    private function milliseconds(int $nanoseconds): int
    {
        return intdiv($nanoseconds + 500000, 1000000);
    }


    private function time(int $milliseconds, string $separator, bool $twoDigitHours): string
    {
        $hours = intdiv($milliseconds, 3600000);

        return sprintf($twoDigitHours ? "%02d:%02d:%02d%s%03d" : "%d:%02d:%02d%s%03d", $hours, intdiv($milliseconds, 60000) % 60,
                       intdiv($milliseconds, 1000) % 60, $separator, $milliseconds % 1000);
    }


    /**
     * Yields the child elements of a master element. Each iteration starts at the next child, whether or not the caller read the data.
     *
     * @return Generator<int, array{id: int, size: int, offset: int}>
     */
    private function children(array $parent): Generator
    {
        $this->requireSize($parent);
        $end      = $parent["offset"] + $parent["size"];
        $position = $parent["offset"];
        while ($position < $end) {
            $this->ebml->seek($position);
            $child = $this->ebml->readElementHeader();
            if ($child === null || $child["size"] === EbmlReader::UNKNOWN_SIZE || $child["offset"] + $child["size"] > $end) {
                throw new ParsingException("The element at byte $position does not fit into its parent element.");
            }

            yield $child;
            $position = $child["offset"] + $child["size"];
        }
    }


    private function skip(array $element): void
    {
        $this->requireSize($element);
        $this->ebml->seek($element["offset"] + $element["size"]);
    }


    private function requireSize(array $element): void
    {
        if ($element["size"] === EbmlReader::UNKNOWN_SIZE) {
            $id = sprintf("0x%X", $element["id"]);
            throw new ParsingException("The element $id at byte {$element['offset']} has an unknown size, which only Segment and Cluster may have.");
        }
    }
}
