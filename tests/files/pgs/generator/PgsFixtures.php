<?php

namespace SubtitleToolbox\Parsers;

require_once __DIR__ . "/PgsFixtureWriter.php";

/**
 * Builds the PGS test fixtures from simple shapes. generate.php writes them to tests/files/pgs/.
 */
final class PgsFixtures
{
    public const TRANSPARENT = 0;
    public const WHITE       = 1;
    public const BLACK       = 2;
    public const YELLOW      = 3;
    public const GRAY        = 4;

    /** Entry id => [Y, Cr, Cb, alpha]. YELLOW is RGB 255, 255, 0 in limited range BT.709. GRAY is half transparent. */
    public const PALETTE = [
        self::TRANSPARENT => [16, 128, 128, 0],
        self::WHITE       => [235, 128, 128, 255],
        self::BLACK       => [16, 128, 128, 255],
        self::YELLOW      => [219, 138, 16, 255],
        self::GRAY        => [126, 128, 128, 128],
    ];

    public const FILES = [
        "shapes_1080p.sup" => "shapes1080p",
        "shapes_576p.sup"  => "shapes576p",
    ];


    /**
     * A subtitle bar: a white box with a 3 pixel black outline, a yellow square in the middle and a dotted line.
     */
    public static function bar(int $width, int $height): string
    {
        $pixels = PgsFixtureWriter::canvas($width, $height);
        $pixels = PgsFixtureWriter::outlinedBox($pixels, $width, 10, 10, $width - 20, $height - 20, 3, self::BLACK, self::WHITE);
        $pixels = PgsFixtureWriter::rectangle($pixels, $width, intdiv($width, 2) - 10, intdiv($height, 2) - 10, 20, 20, self::YELLOW);

        return PgsFixtureWriter::dottedLine($pixels, $width, 20, $height - 16, $width - 40, self::BLACK, self::GRAY);
    }


    /**
     * Six cues on a 1920x1080 screen. Each display set tests one feature, see PgsParserTest.
     */
    public static function shapes1080p(): string
    {
        $w = new PgsFixtureWriter();

        // 1 s to 3.5 s: one 640x90 object at 640, 940. A display set without objects ends it.
        $w->presentation(90000, 1920, 1080, 1, PgsFixtureWriter::STATE_EPOCH_START, 0,
                         [["id" => 0, "window" => 0, "x" => 640, "y" => 940]])
          ->windows(90000, [0 => [640, 940, 640, 90]])
          ->palette(90000, 0, 0, self::PALETTE)
          ->object(90000, 0, 0, 640, 90, self::bar(640, 90))
          ->end(90000);
        $w->presentation(315000, 1920, 1080, 2, PgsFixtureWriter::STATE_NORMAL, 0, [])
          ->windows(315000, [0 => [640, 940, 640, 90]])
          ->end(315000);

        // 5 s to 7 s: two objects, one at the top and one at the bottom, in two windows.
        $w->presentation(450000, 1920, 1080, 3, PgsFixtureWriter::STATE_EPOCH_START, 0, [
            ["id" => 0, "window" => 0, "x" => 760, "y" => 60],
            ["id" => 1, "window" => 1, "x" => 660, "y" => 950],
        ])
          ->windows(450000, [0 => [760, 60, 400, 70], 1 => [660, 950, 600, 80]])
          ->palette(450000, 0, 0, self::PALETTE)
          ->object(450000, 0, 0, 400, 70, self::bar(400, 70))
          ->object(450000, 1, 0, 600, 80, self::bar(600, 80))
          ->end(450000);
        $w->presentation(630000, 1920, 1080, 4, PgsFixtureWriter::STATE_NORMAL, 0, [])
          ->windows(630000, [0 => [760, 60, 400, 70], 1 => [660, 950, 600, 80]])
          ->end(630000);

        // 8 s to 9.5 s: a forced object at the top, split over several object definition segments.
        // 9.5 s to 11 s: a palette update turns the white fill gray.
        $w->presentation(720000, 1920, 1080, 5, PgsFixtureWriter::STATE_EPOCH_START, 1,
                         [["id" => 0, "window" => 0, "x" => 560, "y" => 50, "forced" => true]])
          ->windows(720000, [0 => [560, 50, 800, 100]])
          ->palette(720000, 1, 0, self::PALETTE)
          ->object(720000, 0, 0, 800, 100, self::bar(800, 100), 300)
          ->end(720000);
        $w->presentation(855000, 1920, 1080, 6, PgsFixtureWriter::STATE_NORMAL, 1,
                         [["id" => 0, "window" => 0, "x" => 560, "y" => 50, "forced" => true]], true)
          ->palette(855000, 1, 1, [self::WHITE => self::PALETTE[self::GRAY]])
          ->end(855000);
        $w->presentation(990000, 1920, 1080, 7, PgsFixtureWriter::STATE_NORMAL, 1, [])
          ->windows(990000, [0 => [560, 50, 800, 100]])
          ->end(990000);

        // 12 s to 16 s: a cropped object and a segment of unknown type 0x18.
        // At 14 s an acquisition point repeats the same image, so the cue goes on.
        foreach ([[1080000, 8, PgsFixtureWriter::STATE_EPOCH_START], [1260000, 9, PgsFixtureWriter::STATE_ACQUISITION_POINT]] as [$pts, $number, $state]) {
            $w->presentation($pts, 1920, 1080, $number, $state, 0,
                             [["id" => 2, "window" => 0, "x" => 860, "y" => 900, "crop" => [100, 20, 200, 60]]])
              ->windows($pts, [0 => [860, 900, 200, 60]])
              ->segment($pts, 0x18, "unknown")
              ->palette($pts, 0, 0, self::PALETTE)
              ->object($pts, 2, 0, 400, 100, self::bar(400, 100))
              ->end($pts);
        }
        $w->presentation(1440000, 1920, 1080, 10, PgsFixtureWriter::STATE_NORMAL, 0, [])
          ->windows(1440000, [0 => [860, 900, 200, 60]])
          ->end(1440000);

        // 20 s: a last cue that no display set ends.
        $w->presentation(1800000, 1920, 1080, 11, PgsFixtureWriter::STATE_EPOCH_START, 0,
                         [["id" => 0, "window" => 0, "x" => 640, "y" => 940]])
          ->windows(1800000, [0 => [640, 940, 640, 90]])
          ->palette(1800000, 0, 0, self::PALETTE)
          ->object(1800000, 0, 0, 640, 90, self::bar(640, 90))
          ->end(1800000);

        return $w->bytes();
    }


    /**
     * Two cues on a 720x576 screen, where the palette uses the BT.601 matrix.
     */
    public static function shapes576p(): string
    {
        $w = new PgsFixtureWriter();
        foreach ([[45000, 1, 270000], [360000, 3, null]] as [$start, $number, $end]) {
            $w->presentation($start, 720, 576, $number, PgsFixtureWriter::STATE_EPOCH_START, 0,
                             [["id" => 0, "window" => 0, "x" => 160, "y" => 480]])
              ->windows($start, [0 => [160, 480, 400, 60]])
              ->palette($start, 0, 0, self::PALETTE)
              ->object($start, 0, 0, 400, 60, self::bar(400, 60))
              ->end($start);
            if ($end !== null) {
                $w->presentation($end, 720, 576, $number + 1, PgsFixtureWriter::STATE_NORMAL, 0, [])
                  ->windows($end, [0 => [160, 480, 400, 60]])
                  ->end($end);
            }
        }

        return $w->bytes();
    }


    /**
     * A 640x90 line of 24 outlined blocks of different widths, close to the shape of a line of text.
     */
    public static function blocks(int $seed): string
    {
        $pixels = PgsFixtureWriter::canvas(640, 90);
        $x      = 8;
        for ($index = 0; $index < 24; $index++) {
            $width  = 12 + ($seed + 7 * $index) % 13;
            $height = 30 + ($seed + 5 * $index) % 25;
            $pixels = PgsFixtureWriter::outlinedBox($pixels, 640, $x, 75 - $height, $width, $height, 2, self::BLACK, self::WHITE);
            $x     += $width + 2 + ($index % 5 === 4 ? 10 : 0);
        }

        return $pixels;
    }


    /**
     * $count cues of a 640x90 line of blocks on a 1920x1080 screen, each shown for 2 s with a 1 s gap.
     */
    public static function manyCues(int $count): string
    {
        $w = new PgsFixtureWriter();
        for ($index = 0; $index < $count; $index++) {
            $start = $index * 270000;
            $w->presentation($start, 1920, 1080, 2 * $index, PgsFixtureWriter::STATE_EPOCH_START, 0,
                             [["id" => 0, "window" => 0, "x" => 640, "y" => 940]])
              ->windows($start, [0 => [640, 940, 640, 90]])
              ->palette($start, 0, 0, self::PALETTE)
              ->object($start, 0, 0, 640, 90, self::blocks($index))
              ->end($start)
              ->presentation($start + 180000, 1920, 1080, 2 * $index + 1, PgsFixtureWriter::STATE_NORMAL, 0, [])
              ->end($start + 180000);
        }

        return $w->bytes();
    }
}
