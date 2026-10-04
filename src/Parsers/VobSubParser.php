<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Reads DVD VobSub subtitles: the .idx index from VobSubReadOptions and the .sub program stream from parse().
 *
 * Program stream and SPU layout: http://sam.zoy.org/writings/dvd/subtitles/ and http://dvd.sourceforge.net/dvdinfo/spu.html
 * Decoder: https://github.com/FFmpeg/FFmpeg/blob/master/libavcodec/dvdsubdec.c
 * Index lines: https://github.com/FFmpeg/FFmpeg/blob/master/libavformat/mpeg.c and VSFilter's VobSubFile.cpp
 */
final class VobSubParser extends SubtitleParser
{
    // SP_DCSQ_STM delays count in units of 1024 ticks of the 90 kHz clock.
    private const SECONDS_PER_DELAY_UNIT = 1024 / 90000;

    private const SUBSTREAM_FIRST = 0x20;

    private const PACK_START        = 0xBA;
    private const PRIVATE_STREAM_1  = 0xBD;
    private const FIRST_STREAM_CODE = 0xBB;

    private int $screenWidth;
    private int $screenHeight;

    /** @var list<int> 0xRRGGBB */
    private array $palette = [];

    /** @var list<int>|null 0xRRGGBBAA by pixel value, from an enabled "custom colors" line */
    private ?array $customColors = null;

    private int $trackIndex;
    private string $language;

    /** @var list<array{time: float, filepos: int}> */
    private array $entries = [];


    protected static function formatOptionsClass(): string
    {
        return VobSubReadOptions::class;
    }


    /**
     * Reads the image cues of one track from the .sub content. VobSubReadOptions holds the .idx content.
     * Its $track and $language select the track, else the parser reads the first track.
     */
    protected function read(string $rawSubtitle): Subtitle
    {
        $options = $this->formatOptions();
        if ($options->idx === null) {
            throw new InvalidArgumentException("VobSub needs the .idx content in VobSubReadOptions.");
        }
        $this->palette      = [];
        $this->customColors = null;
        $this->selectTrack($this->readIndex($options->idx), $options->track, $options->language);

        $units = [];
        foreach ($this->entries as $entry) {
            if ($entry["filepos"] >= strlen($rawSubtitle)) {
                throw new ParsingException(sprintf("The filepos %09x of timestamp %.3f is outside the .sub content of %d bytes.",
                                                   $entry["filepos"], $entry["time"], strlen($rawSubtitle)));
            }

            $unit          = $this->decodeUnit($this->readUnit($rawSubtitle, $entry["filepos"]));
            $unit["start"] = $entry["time"] + $unit["startDelay"];
            $unit["stop"]  = $unit["stopDelay"] === null ? null : $entry["time"] + $unit["stopDelay"];
            $units[]       = $unit;
        }

        $subtitle = new Subtitle();
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $this->language);
        foreach ($units as $index => $unit) {
            if ($unit["image"] === null) {
                continue;
            }

            $end = $unit["stop"] ?? min($unit["start"] + $this->options->lastCueDuration,
                                        $units[$index + 1]["start"] ?? INF);
            $subtitle->addCue($unit["image"]->toCue(new SubtitleCue($unit["start"], $end)), false);
        }

        return $subtitle->reIndexCues();
    }


    /**
     * @param list<array{id: string, index: int, entries: list<array{time: float, filepos: int}>}> $tracks
     */
    private function selectTrack(array $tracks, ?int $track, ?string $language): void
    {
        $selected = null;
        foreach ($tracks as $candidate) {
            if (($track === null || $candidate["index"] === $track)
                && ($language === null || strcasecmp($candidate["id"], $language) === 0)) {
                $selected = $candidate;
                break;
            }
        }
        if ($selected === null) {
            $wanted = implode(" and ", array_filter([
                $track === null ? null : "index $track",
                $language === null ? null : "language \"$language\"",
            ]));
            throw new ParsingException($wanted === ""
                ? "The .idx content has no \"id:\" line."
                : "The .idx content has no track with $wanted.");
        }

        $this->trackIndex = $selected["index"];
        $this->language   = $selected["id"];
        $this->entries    = $selected["entries"];
        usort($this->entries, fn (array $entry1, array $entry2): int => $entry1["time"] <=> $entry2["time"]);
    }


    /**
     * @return list<array{id: string, index: int, entries: list<array{time: float, filepos: int}>}>
     */
    private function readIndex(string $idx): array
    {
        $lines = explode("\n", StringHelpers::normalizeEOLs(StringHelpers::removeUtf8Bom($idx)));
        if (!str_contains($lines[0], "VobSub index file")) {
            throw new ParsingException("The .idx content does not start with the \"VobSub index file\" line.");
        }

        $tracks = [];
        $delay  = 0.0;
        $size   = null;
        foreach (array_slice($lines, 1) as $line) {
            $line = trim($line);
            if ($line === "" || $line[0] === "#" || !str_contains($line, ":")) {
                continue;
            }

            [$key, $value] = array_map("trim", explode(":", $line, 2));
            switch (strtolower($key)) {
                case "size":
                    if (!preg_match('/^(\d+)\s*x\s*(\d+)$/i', $value, $matches) || $matches[1] < 1 || $matches[2] < 1) {
                        throw new ParsingException("The .idx size line is invalid: $line");
                    }
                    $size = [(int) $matches[1], (int) $matches[2]];
                    break;

                case "palette":
                    $this->palette = $this->readColors($value, 16, $line);
                    break;

                case "custom colors":
                    if (!preg_match('/^(on|off|1|0)\s*,\s*tridx\s*:\s*([01]{4})\s*,\s*colors\s*:\s*(.*)$/i', $value, $matches)) {
                        throw new ParsingException("The .idx custom colors line is invalid: $line");
                    }
                    $this->customColors = null;
                    if (in_array(strtolower($matches[1]), ["on", "1"], true)) {
                        $this->customColors = array_map(
                            fn (int $rgb, int $pixelValue): int => $rgb << 8 | ($matches[2][$pixelValue] === "1" ? 0x00 : 0xFF),
                            $this->readColors($matches[3], 4, $line),
                            [0, 1, 2, 3]
                        );
                    }
                    break;

                case "id":
                    if (!preg_match('/^([^,]*),\s*index:\s*(\d+)$/i', $value, $matches) || $matches[2] > 31) {
                        throw new ParsingException("The .idx id line is invalid: $line");
                    }
                    $tracks[] = ["id" => trim($matches[1]), "index" => (int) $matches[2], "entries" => []];
                    $delay    = 0.0;
                    break;

                case "delay":
                    // VSFilter adds up the delay lines of a track and resets the sum at each id line.
                    $delay += $this->readTime($value, $line);
                    break;

                case "timestamp":
                    if ($tracks === []) {
                        throw new ParsingException("The .idx timestamp line comes before any id line: $line");
                    }
                    if (!preg_match('/^(.+?),\s*filepos:\s*([0-9a-f]+)$/i', $value, $matches)) {
                        throw new ParsingException("The .idx timestamp line is invalid: $line");
                    }
                    $tracks[array_key_last($tracks)]["entries"][] = [
                        "time"    => $this->readTime($matches[1], $line) + $delay,
                        "filepos" => (int) hexdec($matches[2]),
                    ];
                    break;
            }
        }

        if ($size === null) {
            throw new ParsingException("The .idx content has no size line.");
        }
        if ($this->palette === [] && $this->customColors === null) {
            throw new ParsingException("The .idx content has no palette line.");
        }
        [$this->screenWidth, $this->screenHeight] = $size;

        return $tracks;
    }


    /**
     * @return list<int>
     */
    private function readColors(string $value, int $count, string $line): array
    {
        $colors = array_map("trim", explode(",", $value));
        if (count($colors) !== $count || preg_grep('/^[0-9a-f]{1,6}$/i', $colors, PREG_GREP_INVERT) !== []) {
            throw new ParsingException("The .idx line needs $count colors as hex RGB: $line");
        }

        return array_map("hexdec", $colors);
    }


    private function readTime(string $value, string $line): float
    {
        if (!preg_match('/^([+-]?)(\d+):(\d{1,2}):(\d{1,2})[:.,](\d{1,3})$/', trim($value), $matches)) {
            throw new ParsingException("The .idx time is invalid: $line");
        }
        $seconds = $matches[2] * 3600 + $matches[3] * 60 + $matches[4] + $matches[5] / 1000;

        return $matches[1] === "-" ? -$seconds : $seconds;
    }


    /**
     * Joins the private stream 1 payloads of the selected track from $filepos on, until they hold one whole SPU.
     */
    private function readUnit(string $sub, int $filepos): string
    {
        $unit     = "";
        $unitSize = null;
        $position = $filepos;
        $length   = strlen($sub);

        while ($unitSize === null || strlen($unit) < $unitSize) {
            if ($position + 6 > $length || substr($sub, $position, 3) !== "\x00\x00\x01") {
                throw new ParsingException(sprintf("The .sub content has no complete subtitle packet at filepos %09x. " .
                                                   "It ends or has no MPEG start code at byte %d.", $filepos, $position));
            }

            $code = ord($sub[$position + 3]);
            if ($code === self::PACK_START) {
                if ($position + 14 > $length || (ord($sub[$position + 4]) & 0xC0) !== 0x40) {
                    throw new ParsingException("The .sub content has no MPEG-2 pack header at byte $position.");
                }
                $position += 14 + (ord($sub[$position + 13]) & 0x07);
                continue;
            }
            if ($code < self::FIRST_STREAM_CODE) {
                throw new ParsingException(sprintf("The .sub content ends at byte %d before the subtitle packet at filepos %09x is complete.",
                                                   $position, $filepos));
            }

            $packetEnd = $position + 6 + unpack("n", $sub, $position + 4)[1];
            if ($packetEnd > $length) {
                throw new ParsingException("The .sub packet at byte $position is longer than the content.");
            }
            if ($code === self::PRIVATE_STREAM_1) {
                $payloadStart = $position + 9 + ord($sub[$position + 8]);
                $substream    = $payloadStart < $packetEnd ? ord($sub[$payloadStart]) : -1;
                if ($substream === self::SUBSTREAM_FIRST + $this->trackIndex) {
                    $unit .= substr($sub, $payloadStart + 1, $packetEnd - $payloadStart - 1);
                }
            }
            $position = $packetEnd;

            if ($unitSize === null && strlen($unit) >= 2) {
                $unitSize = unpack("n", $unit)[1];
            }
        }

        return substr($unit, 0, $unitSize);
    }


    /**
     * Runs the control sequences of a subpicture unit (SPU) and decodes its bitmap.
     *
     * @return array{startDelay: float, stopDelay: ?float, image: ?CueImage}
     */
    private function decodeUnit(string $unit): array
    {
        $size     = strlen($unit);
        $sequence = $size >= 4 ? unpack("n", $unit, 2)[1] : 0;
        if ($sequence < 4 || $sequence + 4 > $size) {
            throw new ParsingException("The subtitle packet of $size bytes has no valid control sequence offset.");
        }

        $startDelay = null;
        $stopDelay  = null;
        $forced     = false;
        $colors     = [0, 0, 0, 0];
        $alphas     = [0, 0, 0, 0];
        $area       = null;
        $offsets    = null;
        while (true) {
            $delay    = unpack("n", $unit, $sequence)[1] * self::SECONDS_PER_DELAY_UNIT;
            $next     = unpack("n", $unit, $sequence + 2)[1];
            $position = $sequence + 4;
            // Sequences after the start sequence animate the colors or the area, which one cue image cannot hold.
            $shown    = $startDelay !== null;

            while ($position < $size && ($command = ord($unit[$position++])) !== 0xFF) {
                $argumentSize = [0x03 => 2, 0x04 => 2, 0x05 => 6, 0x06 => 4][$command] ?? 0;
                if ($position + $argumentSize > $size) {
                    throw new ParsingException("The subtitle packet ends inside command " . sprintf("%02x", $command) . ".");
                }
                $arguments = array_values(unpack("C*", substr($unit, $position, $argumentSize)) ?: []);

                if ($command === 0x00 || $command === 0x01) {
                    $startDelay ??= $delay;
                    $forced       = $forced || $command === 0x00;
                } elseif ($command === 0x02) {
                    $stopDelay ??= $delay;
                } elseif ($command === 0x07) {
                    $argumentSize = $position + 2 <= $size ? unpack("n", $unit, $position)[1] : $size;
                } elseif ($command > 0x07) {
                    break;
                } elseif (!$shown && $command === 0x03) {
                    $colors = $this->readNibbles($arguments);
                } elseif (!$shown && $command === 0x04) {
                    $alphas = $this->readNibbles($arguments);
                } elseif (!$shown && $command === 0x05) {
                    $area = [
                        $arguments[0] << 4 | $arguments[1] >> 4,
                        ($arguments[1] & 0x0F) << 8 | $arguments[2],
                        $arguments[3] << 4 | $arguments[4] >> 4,
                        ($arguments[4] & 0x0F) << 8 | $arguments[5],
                    ];
                } elseif (!$shown && $command === 0x06) {
                    $offsets = [$arguments[0] << 8 | $arguments[1], $arguments[2] << 8 | $arguments[3]];
                }
                $position += $argumentSize;
            }

            if ($next <= $sequence || $next + 4 > $size) {
                break;
            }
            $sequence = $next;
        }

        $image = null;
        if ($area !== null && $offsets !== null && $area[1] >= $area[0] && $area[3] >= $area[2]) {
            [$x1, $x2, $y1, $y2] = $area;
            $image = $this->decodeImage($unit, $offsets, $x1, $y1, $x2 - $x1 + 1, $y2 - $y1 + 1,
                                        $this->pixelColors($colors, $alphas), $forced);
        }

        return ["startDelay" => $startDelay ?? 0.0, "stopDelay" => $stopDelay, "image" => $image];
    }


    /**
     * Returns the four nibbles of a SET_COLOR or SET_CONTR argument, ordered by pixel value: background, pattern,
     * emphasis 1, emphasis 2.
     *
     * @param list<int> $bytes
     * @return list<int>
     */
    private function readNibbles(array $bytes): array
    {
        return [$bytes[1] & 0x0F, $bytes[1] >> 4, $bytes[0] & 0x0F, $bytes[0] >> 4];
    }


    /**
     * @param list<int> $colors palette indexes by pixel value
     * @param list<int> $alphas contrast from 0 (transparent) to 15 (opaque) by pixel value
     * @return list<int> 0xRRGGBBAA by pixel value
     */
    private function pixelColors(array $colors, array $alphas): array
    {
        if ($this->customColors !== null) {
            return $this->customColors;
        }

        return array_map(fn (int $color, int $alpha): int => ($this->palette[$color] ?? 0) << 8 | $alpha * 17, $colors, $alphas);
    }


    /**
     * Decodes the 2-bit run-length bitmap. The top field holds the even lines and the bottom field the odd lines.
     *
     * @param array{int, int} $offsets
     * @param list<int> $pixelColors
     */
    private function decodeImage(string $unit, array $offsets, int $x, int $y, int $width, int $height,
                                 array $pixelColors, bool $forced): CueImage
    {
        $tooLarge = CueImage::sizeLimitError($width, $height);
        if ($tooLarge !== null) {
            throw new ParsingException("The subtitle packet cannot be read: $tooLarge");
        }
        $pixels    = array_fill(0, $width * $height, $pixelColors[0]);
        $nibbleEnd = strlen($unit) * 2;
        foreach ($offsets as $field => $offset) {
            $nibble = $offset * 2;
            for ($row = $field; $row < $height; $row += 2) {
                $column = 0;
                while ($column < $width) {
                    $code = 0;
                    foreach ([0x4, 0x10, 0x40, 0x100] as $limit) {
                        if ($nibble >= $nibbleEnd) {
                            throw new ParsingException("The bitmap of the subtitle packet ends before line $row is complete.");
                        }
                        $code = $code << 4 | (ord($unit[$nibble >> 1]) >> ($nibble & 1 ? 0 : 4) & 0x0F);
                        $nibble++;
                        if ($code >= $limit) {
                            break;
                        }
                    }

                    $run   = $code >> 2 === 0 ? $width - $column : min($code >> 2, $width - $column);
                    $color = $pixelColors[$code & 0x03];
                    $first = $row * $width + $column;
                    for ($pixel = $first; $pixel < $first + $run; $pixel++) {
                        $pixels[$pixel] = $color;
                    }
                    $column += $run;
                }
                $nibble += $nibble & 1;
            }
        }

        return new CueImage(PngEncoder::encode($width, $height, $pixels), $x, $y, $width, $height,
                            $this->screenWidth, $this->screenHeight, $forced);
    }
}
