<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\OcrException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngDecoder;
use SubtitleToolbox\Markup;

final class TesseractOcrEngine implements OcrEngine
{
    public const INSTALL_HINT = "Install Tesseract with: apt install tesseract-ocr (Debian, Ubuntu), apk add " .
                                "tesseract-ocr tesseract-ocr-data-eng (Alpine), dnf install tesseract (Fedora), brew install tesseract " .
                                "(macOS), or the installer from https://github.com/UB-Mannheim/tesseract/wiki (Windows).";

    // Tesseract finds no text that touches the image edge, so the image gets a white border.
    private const BORDER = 10;

    /** @var array<string, list<string>> the installed languages by program */
    private static array $languages = [];


    /**
     * Reads image cues with the tesseract program. $language is a Tesseract code such as "deu" or "deu+eng" for the
     * cues where recognizeText() passes no language.
     *
     * @param int        $pageSegmentationMode the --psm value of tesseract. 6 reads the image as one block of text
     * @param float|null $scale                the factor from 1 to 8 that scales the image up before OCR, or null for
     *                                         2 on screens below 720 lines and 1 on larger screens
     * @param bool       $invert               draws light text dark on white, as Tesseract expects
     * @param int|null   $threshold            makes grey levels below this value from 1 to 255 black and the others
     *                                         white, or null to keep the grey levels
     */
    public function __construct(
        private readonly string $language = "eng",
        private readonly int $pageSegmentationMode = 6,
        private readonly string $program = "tesseract",
        private readonly ?float $scale = null,
        private readonly bool $invert = true,
        private readonly ?int $threshold = null,
    ) {
        if ($pageSegmentationMode < 0 || $pageSegmentationMode > 13) {
            throw new InvalidArgumentException("Cannot create a TesseractOcrEngine with page segmentation mode " .
                                               "$pageSegmentationMode - the mode must be from 0 to 13!");
        }
        if ($scale !== null && ($scale < 1 || $scale > 8)) {
            throw new InvalidArgumentException("Cannot create a TesseractOcrEngine with scale $scale - the scale " .
                                               "must be from 1 to 8!");
        }
        if ($threshold !== null && ($threshold < 1 || $threshold > 255)) {
            throw new InvalidArgumentException("Cannot create a TesseractOcrEngine with threshold $threshold - the " .
                                               "threshold must be from 1 to 255!");
        }
    }


    /**
     * Returns true when $program runs and prints a Tesseract version.
     */
    public static function isInstalled(string $program = "tesseract"): bool
    {
        // Tesseract 4.0 and older print the version to standard error.
        [$code, $output, $error] = self::run([$program, "--version"]);

        return $code === 0 && str_starts_with(ltrim($output . $error), "tesseract");
    }


    public function recognize(CueImage $image, ?string $language): RecognizedText
    {
        $language ??= $this->language;
        $this->requireLanguages($language);

        $file = tempnam(sys_get_temp_dir(), "subtitle-toolbox-ocr-");
        try {
            file_put_contents($file, $this->toPgm($image));
            [$code, $output, $error] = self::run([$this->program, $file, "stdout", "-l", $language,
                                                  "--psm", (string)$this->pageSegmentationMode, "-c",
                                                  "tessedit_create_tsv=1"]);
        } finally {
            unlink($file);
        }
        if ($code !== 0) {
            throw new OcrException("Cannot read the cue image at {$image->x}, {$image->y} - tesseract " .
                                   "exits with code $code: " . trim($error));
        }

        return self::fromTsv($output);
    }


    /**
     * Builds the result from the TSV output of tesseract: one line of text per text line, and the mean word confidence.
     *
     * @internal
     */
    public static function fromTsv(string $tsv): RecognizedText
    {
        $lines       = [];
        $confidences = [];
        foreach (array_slice(explode("\n", $tsv), 1) as $row) {
            $columns = explode("\t", rtrim($row, "\r"));
            if (count($columns) < 12 || $columns[0] !== "5" || trim($columns[11]) === "") {
                continue;
            }
            $key           = "$columns[2]/$columns[3]/$columns[4]";
            $lines[$key][] = Markup::escapeText(trim($columns[11]));
            $confidences[] = max(0.0, min(100.0, (float)$columns[10])) / 100;
        }

        return new RecognizedText(array_values(array_map(fn (array $words): string => implode(" ", $words), $lines)),
                             $confidences === [] ? null : array_sum($confidences) / count($confidences));
    }


    private function requireLanguages(string $language): void
    {
        if (!isset(self::$languages[$this->program])) {
            if (!self::isInstalled($this->program)) {
                throw new OcrException("Cannot run OCR with Tesseract - the program \"$this->program\" is " .
                                       "missing! " . self::INSTALL_HINT);
            }
            [, $output, $error]              = self::run([$this->program, "--list-langs"]);
            $lines                           = array_map(trim(...), explode("\n", trim($output . $error)));
            self::$languages[$this->program] = array_values(array_filter(array_slice($lines, 1)));
        }

        $missing = array_diff(explode("+", $language), self::$languages[$this->program]);
        if ($missing !== []) {
            throw new OcrException("Cannot run OCR with Tesseract in the language \"$language\" - the " .
                                   "language data of " . implode(", ", $missing) . " is missing! Install " .
                                   "it, for example with apt install tesseract-ocr-" . reset($missing) .
                                   ". The installed languages are: " .
                                   implode(", ", self::$languages[$this->program]) . ".");
        }
    }


    /**
     * Draws the image on black as a grey image, inverts it for dark text on white, scales it and adds a border.
     */
    private function toPgm(CueImage $image): string
    {
        ['width' => $width, 'height' => $height, 'pixels' => $pixels] = PngDecoder::decode($image->png);

        $grey = [];
        foreach ($pixels as $pixel) {
            $alpha  = ($pixel & 0xFF) / 255;
            $luma   = 0.299 * ($pixel >> 24 & 0xFF) + 0.587 * ($pixel >> 16 & 0xFF) + 0.114 * ($pixel >> 8 & 0xFF);
            $value  = $luma * $alpha;
            $grey[] = $this->invert ? 255 - $value : $value;
        }

        $scale     = $this->scale ?? ($image->screenHeight < 720 ? 2.0 : 1.0);
        $outWidth  = (int)round($width * $scale);
        $outHeight = (int)round($height * $scale);
        $border    = str_repeat("\xFF", $outWidth + 2 * self::BORDER);
        $rows      = array_fill(0, self::BORDER, $border);
        for ($y = 0; $y < $outHeight; $y++) {
            $sourceY = min($height - 1.0, max(0.0, ($y + 0.5) / $scale - 0.5));
            $y0      = (int)$sourceY;
            $y1      = min($height - 1, $y0 + 1);
            $fy      = $sourceY - $y0;
            $row     = str_repeat("\xFF", self::BORDER);
            for ($x = 0; $x < $outWidth; $x++) {
                $sourceX = min($width - 1.0, max(0.0, ($x + 0.5) / $scale - 0.5));
                $x0      = (int)$sourceX;
                $x1      = min($width - 1, $x0 + 1);
                $fx      = $sourceX - $x0;
                $value   = ($grey[$y0 * $width + $x0] * (1 - $fx) + $grey[$y0 * $width + $x1] * $fx) * (1 - $fy)
                         + ($grey[$y1 * $width + $x0] * (1 - $fx) + $grey[$y1 * $width + $x1] * $fx) * $fy;
                if ($this->threshold !== null) {
                    $value = $value < $this->threshold ? 0 : 255;
                }
                $row .= chr((int)round($value));
            }
            $rows[] = $row . str_repeat("\xFF", self::BORDER);
        }
        array_push($rows, ...array_fill(0, self::BORDER, $border));

        return "P5\n" . ($outWidth + 2 * self::BORDER) . " " . ($outHeight + 2 * self::BORDER) . "\n255\n" . implode("", $rows);
    }


    /**
     * @param list<string> $command
     * @return array{int, string, string} the exit code, standard output and standard error
     */
    private static function run(array $command): array
    {
        $process = @proc_open($command, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        if ($process === false) {
            return [-1, "", ""];
        }
        $output = (string)stream_get_contents($pipes[1]);
        $error  = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }
}
