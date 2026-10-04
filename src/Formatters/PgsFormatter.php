<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PaletteReducer;
use SubtitleToolbox\Image\PngDecoder;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

/**
 * Writes image cues as a Blu-ray PGS (.sup) file, the inverse of PgsParser. See PgsParser for the specs.
 */
final class PgsFormatter extends SubtitleFormatter implements ImageFormatter
{
    private const PTS_PER_SECOND = 90000;
    private const MAX_PTS        = 0xFFFFFFFF;
    private const MAX_FIELD      = 0xFFFF;

    private const SEGMENT_PALETTE      = 0x14;
    private const SEGMENT_OBJECT       = 0x15;
    private const SEGMENT_PRESENTATION = 0x16;
    private const SEGMENT_WINDOW       = 0x17;
    private const SEGMENT_END          = 0x80;

    private const STATE_NORMAL      = 0x00;
    private const STATE_EPOCH_START = 0x80;
    private const FLAG_FORCED       = 0x40;
    private const SEQUENCE_FIRST    = 0x80;
    private const SEQUENCE_LAST     = 0x40;

    // The frame rate code that Blu-ray discs and the PGS muxers write. Players ignore it.
    private const FRAME_RATE = 0x10;

    private const MAX_SEGMENT_DATA = 0xFFFF;
    private const MAX_RUN          = 0x3FFF;

    // PgsParser uses BT.601 up to this video height and BT.709 above it.
    private const SD_MAX_HEIGHT = 576;
    private const MATRIX_BT709  = [0.2126, 0.0722];
    private const MATRIX_BT601  = [0.299, 0.114];

    // Clamped colors such as BT.601 yellow need a step of 2 to find the code that the parser decodes to the same RGB.
    private const SEARCH_STEPS = [0, -1, 1, -2, 2];

    private int $compositionNumber = 0;

    /** @var array<string, array{int, int, int}> matrix and 0xRRGGBB => [Y, Cr, Cb] */
    private array $ycrcb = [];


    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $this->formatOptions($options);
        $cues = array_values($subtitle->getCues());
        usort($cues, fn (SubtitleCue $a, SubtitleCue $b): int => $a->getStart() <=> $b->getStart());

        $this->compositionNumber = 0;
        $output                  = "";
        foreach ($cues as $index => $cue) {
            if (!CueImage::isImageCue($cue)) {
                throw new InvalidArgumentException("Cannot write cue [{$cue->getStart()} >>> {$cue->getEnd()}] as PGS - " .
                                                   "the cue holds no image, and PgsFormatter does not render text!");
            }

            $image = CueImage::fromCue($cue);
            $start = $this->pts($cue, $cue->getStart());
            $end   = $this->pts($cue, $cue->getEnd());
            $next  = isset($cues[$index + 1]) ? $this->pts($cues[$index + 1], $cues[$index + 1]->getStart()) : null;

            $output .= $this->showImage($cue, $image, $start);
            if ($next === null || $next > $end) {
                $output .= $this->clearScreen($image, $end);
            }
        }

        return $output;
    }


    private function showImage(SubtitleCue $cue, CueImage $image, int $pts): string
    {
        foreach ([$image->x, $image->y, $image->width, $image->height, $image->screenWidth, $image->screenHeight] as $value) {
            if ($value < 0 || $value > self::MAX_FIELD) {
                throw new InvalidArgumentException("Cannot write cue [{$cue->getStart()} >>> {$cue->getEnd()}] as PGS - " .
                                                   "the image position and size must be from 0 to " . self::MAX_FIELD . "!");
            }
        }

        ["width" => $width, "height" => $height, "pixels" => $pixels] = PngDecoder::decode($image->png);
        if ($width !== $image->width || $height !== $image->height) {
            throw new InvalidArgumentException("Cannot write cue [{$cue->getStart()} >>> {$cue->getEnd()}] as PGS - " .
                                               "the PNG has {$width}x{$height} pixels, but the image data says " .
                                               "{$image->width}x{$image->height}!");
        }

        ["palette" => $palette, "indexes" => $indexes] = PaletteReducer::reduce($pixels);

        $presentation = $this->presentation($image, self::STATE_EPOCH_START, 1)
            . pack("nCCnn", 0, 0, $cue->isForced() ? self::FLAG_FORCED : 0, $image->x, $image->y);

        return $this->segment($pts, self::SEGMENT_PRESENTATION, $presentation)
            . $this->segment($pts, self::SEGMENT_WINDOW, $this->window($image))
            . $this->segment($pts, self::SEGMENT_PALETTE, $this->palette($palette, $image->screenHeight > self::SD_MAX_HEIGHT))
            . $this->objects($pts, $width, $height, $this->encodeRle($indexes, $width))
            . $this->segment($pts, self::SEGMENT_END, "");
    }


    private function clearScreen(CueImage $image, int $pts): string
    {
        return $this->segment($pts, self::SEGMENT_PRESENTATION, $this->presentation($image, self::STATE_NORMAL, 0))
            . $this->segment($pts, self::SEGMENT_WINDOW, $this->window($image))
            . $this->segment($pts, self::SEGMENT_END, "");
    }


    private function presentation(CueImage $image, int $state, int $objectCount): string
    {
        $number                  = $this->compositionNumber;
        $this->compositionNumber = ($number + 1) & 0xFFFF;

        return pack("nnCnCCCC", $image->screenWidth, $image->screenHeight, self::FRAME_RATE, $number, $state, 0, 0, $objectCount);
    }


    private function window(CueImage $image): string
    {
        return pack("CCnnnn", 1, 0, $image->x, $image->y, $image->width, $image->height);
    }


    /**
     * @param list<int> $palette 0xRRGGBBAA colors
     */
    private function palette(array $palette, bool $highDefinition): string
    {
        $data = pack("CC", 0, 0);
        foreach ($palette as $entryId => $color) {
            [$luma, $chromaRed, $chromaBlue] = $this->toYcrcb($color >> 8 & 0xFFFFFF, $highDefinition);
            $data .= pack("C5", $entryId, $luma, $chromaRed, $chromaBlue, $color & 0xFF);
        }

        return $data;
    }


    /**
     * Splits the object data into a first segment with the size and further segments of at most 65,535 bytes each.
     */
    private function objects(int $pts, int $width, int $height, string $rle): string
    {
        $data      = pack("nn", $width, $height) . $rle;
        $fragments = array_merge([substr($data, 0, self::MAX_SEGMENT_DATA - 7)],
                                 str_split(substr($data, self::MAX_SEGMENT_DATA - 7), self::MAX_SEGMENT_DATA - 4));
        $fragments = array_values(array_filter($fragments, fn (string $fragment): bool => $fragment !== ""));

        $output = "";
        foreach ($fragments as $index => $fragment) {
            $sequence = ($index === 0 ? self::SEQUENCE_FIRST : 0) | ($index === count($fragments) - 1 ? self::SEQUENCE_LAST : 0);
            $header   = pack("nCC", 0, 0, $sequence);
            if ($index === 0) {
                $header .= pack("Cn", strlen($data) >> 16, strlen($data) & 0xFFFF);
            }
            $output .= $this->segment($pts, self::SEGMENT_OBJECT, $header . $fragment);
        }

        return $output;
    }


    /**
     * Encodes rows of palette entry bytes with the run-length codes that PgsParser decodes.
     */
    private function encodeRle(string $indexes, int $width): string
    {
        $rle = "";
        foreach (str_split($indexes, $width) as $row) {
            $position = 0;
            while ($position < $width) {
                $entry = $row[$position];
                $run   = min(strspn($row, $entry, $position), self::MAX_RUN);
                $color = ord($entry);
                $rle  .= match (true) {
                    $color !== 0 && $run <= 2 => str_repeat($entry, $run),
                    $color === 0 && $run < 64 => "\0" . chr($run),
                    $color === 0              => "\0" . chr(0x40 | $run >> 8) . chr($run & 0xFF),
                    $run < 64                 => "\0" . chr(0x80 | $run) . $entry,
                    default                   => "\0" . chr(0xC0 | $run >> 8) . chr($run & 0xFF) . $entry,
                };
                $position += $run;
            }
            $rle .= "\0\0";
        }

        return $rle;
    }


    /**
     * Returns the limited range [Y, Cr, Cb] that PgsParser turns back into the same RGB color, or the nearest one.
     *
     * @return array{int, int, int}
     */
    private function toYcrcb(int $rgb, bool $highDefinition): array
    {
        $key = ($highDefinition ? "709:" : "601:") . $rgb;
        if (isset($this->ycrcb[$key])) {
            return $this->ycrcb[$key];
        }

        [$kr, $kb] = $highDefinition ? self::MATRIX_BT709 : self::MATRIX_BT601;
        $target    = [$rgb >> 16, $rgb >> 8 & 0xFF, $rgb & 0xFF];
        $y         = $kr * $target[0] + (1 - $kr - $kb) * $target[1] + $kb * $target[2];
        $center    = [(int) round(16 + $y * 219 / 255),
                      (int) round(128 + ($target[0] - $y) / (2 * (1 - $kr)) * 224 / 255),
                      (int) round(128 + ($target[2] - $y) / (2 * (1 - $kb)) * 224 / 255)];

        $best      = $center;
        $bestError = PHP_INT_MAX;
        foreach (self::SEARCH_STEPS as $lumaStep) {
            foreach (self::SEARCH_STEPS as $redStep) {
                foreach (self::SEARCH_STEPS as $blueStep) {
                    $candidate = [max(16, min(235, $center[0] + $lumaStep)),
                                  max(16, min(240, $center[1] + $redStep)),
                                  max(16, min(240, $center[2] + $blueStep))];
                    $error     = 0;
                    foreach ($this->toRgb($candidate, $kr, $kb) as $channel => $value) {
                        $error += ($value - $target[$channel]) ** 2;
                    }
                    if ($error < $bestError) {
                        [$best, $bestError] = [$candidate, $error];
                    }
                }
            }
        }

        return $this->ycrcb[$key] = $best;
    }


    /**
     * The conversion of PgsParser::colorMap().
     *
     * @param array{int, int, int} $ycrcb
     * @return array{int, int, int}
     */
    private function toRgb(array $ycrcb, float $kr, float $kb): array
    {
        [$luma, $chromaRed, $chromaBlue] = $ycrcb;
        $kg = 1 - $kr - $kb;
        $y  = ($luma - 16) * 255 / 219;
        $cr = ($chromaRed - 128) * 255 / 224;
        $cb = ($chromaBlue - 128) * 255 / 224;

        return array_map(fn (float $value): int => max(0, min(255, (int) round($value))), [
            $y + 2 * (1 - $kr) * $cr,
            $y - 2 * $kb * (1 - $kb) / $kg * $cb - 2 * $kr * (1 - $kr) / $kg * $cr,
            $y + 2 * (1 - $kb) * $cb,
        ]);
    }


    private function pts(SubtitleCue $cue, float $seconds): int
    {
        $pts = (int) round($seconds * self::PTS_PER_SECOND);
        if ($pts < 0 || $pts > self::MAX_PTS) {
            throw new InvalidArgumentException("Cannot write cue [{$cue->getStart()} >>> {$cue->getEnd()}] as PGS - " .
                                               "a time stamp must be from 0 to " . self::MAX_PTS . " ticks of 90 kHz!");
        }

        return $pts;
    }


    private function segment(int $pts, int $type, string $data): string
    {
        return "PG" . pack("NNCn", $pts, 0, $type, strlen($data)) . $data;
    }
}
