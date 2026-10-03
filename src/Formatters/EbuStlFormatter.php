<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Encoding\CodePage;
use SubtitleToolbox\Encoding\Iso6937;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Options;
use SubtitleToolbox\Parsers\EbuStlParser as Stl;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Writes EBU STL files as defined in EBU Tech 3264: https://tech.ebu.ch/docs/tech/tech3264.pdf
 */
class EbuStlFormatter extends SubtitleFormatter
{
    public const OPTION_FRAME_RATE = "OPTION_FRAME_RATE";

    // The values of a new GSI block. EBU Tech 3264 section 4.2.5 fills unused bytes with spaces.
    private const DEFAULT_GSI = [
        "CPN" => "850", "DSC" => "1", "CCT" => "00", "RN" => "00", "MNC" => "40", "MNR" => "23",
        "TCS" => "1", "TCP" => "00000000", "TND" => "1", "DSN" => "1",
    ];

    private const FIRST_SUBTITLE_NUMBER = 1;
    private const MAX_EXTENSION_BLOCKS  = 0xF0;

    private FrameRate $frameRate;

    private float $offset;

    private string $characterCodeTable;

    private int $maxRow;

    private bool $teletext;

    private bool $stripAll;


    public function format(Subtitle $subtitle, array $options = []): string
    {
        $this->rejectUnknownOptions($options);
        $data = $subtitle->getFormatData(Stl::FORMAT_DATA_KEY);
        $gsi  = ($data["gsi"] ?? []) + self::DEFAULT_GSI;

        $fps = (int) ($options[self::OPTION_FRAME_RATE] ?? Stl::FRAME_RATES[$gsi["DFC"] ?? ""] ?? 25);
        if (!in_array($fps, Stl::FRAME_RATES, true)) {
            throw new InvalidArgumentException("The EBU STL formatter writes 25 or 30 fps, got $fps.");
        }

        if (!array_key_exists($gsi["CCT"], Stl::CHARACTER_CODE_TABLES)) {
            throw new InvalidArgumentException("The character code table \"{$gsi["CCT"]}\" is not 00, 01, 02, 03 or 04.");
        }

        $gsi["DFC"]               = array_search($fps, Stl::FRAME_RATES, true);
        $this->frameRate          = new FrameRate($fps);
        $this->offset             = empty($data["startOfProgrammeSubtracted"]) ? 0.0 : Stl::timeCodeToSeconds($gsi["TCP"], $this->frameRate);
        $this->characterCodeTable = $gsi["CCT"];
        $this->maxRow             = Stl::maxRow($gsi);
        $this->teletext           = in_array($gsi["DSC"], ["1", "2"], true);
        $this->stripAll           = (bool) (Options::flag($options, parent::OPTION_STRIP_ALL_XML_TAGS) ?? false);

        $sets = $this->subtitleSets($subtitle, $data["comments"] ?? []);

        $number = $data["firstSubtitleNumber"] ?? self::FIRST_SUBTITLE_NUMBER;
        $blocks = "";
        foreach ($sets as $set) {
            if ($number > 0xFFFF) {
                throw new InvalidArgumentException("EBU STL allows subtitle numbers up to 65535.");
            }

            foreach ($set["blocks"] as $block) {
                $blocks .= substr_replace($block, pack("v", $number), 1, 2);
            }
            $number++;
        }

        return $this->formatGsi($subtitle, $gsi, $data, $sets) . $blocks;
    }


    /**
     * @return list<array{blocks: list<string>, comment: bool}>
     */
    private function subtitleSets(Subtitle $subtitle, array $storedComments): array
    {
        $cues     = array_values($subtitle->getCues());
        $comments = $subtitle->getComments();
        $sets     = [];
        foreach ([...array_keys($cues), count($cues)] as $index) {
            while ($comments !== [] && $comments[0]["beforeCueIndex"] <= $index) {
                $timeCode = $this->timeCode(isset($cues[$index]) ? $cues[$index]->getStart() : (end($cues) ?: new SubtitleCue())->getEnd());
                $sets[]   = ["blocks" => $this->commentBlocks(array_shift($comments)["text"], $storedComments, $timeCode), "comment" => true];
            }

            if (isset($cues[$index])) {
                $sets[] = ["blocks" => $this->cueBlocks($cues[$index]), "comment" => false];
            }
        }

        return $sets;
    }


    /**
     * Writes the stored blocks of a comment with the same text, or new blocks with the comment flag set.
     *
     * @return list<string>
     */
    private function commentBlocks(string $text, array &$storedComments, string $timeCode): array
    {
        foreach ($storedComments as $index => $stored) {
            if ($stored["text"] === $text) {
                unset($storedComments[$index]);

                return array_map("hex2bin", $stored["blocks"]);
            }
        }

        $bytes = implode(chr(Stl::NEW_LINE), array_map($this->encodeCharacters(...), explode("\n", $text)));
        [$verticalPosition, $justificationCode] = $this->position(2, count(explode("\n", $text)));

        return $this->textBlocks($bytes, $this->header(0, 0, $timeCode, $timeCode, $verticalPosition, $justificationCode, 1));
    }


    /**
     * Writes the stored blocks of an unchanged cue with new times and position, or new blocks from the core markup.
     *
     * @return list<string>
     */
    private function cueBlocks(SubtitleCue $cue): array
    {
        $stored    = $cue->getFormatData(Stl::FORMAT_DATA_KEY);
        $alignment = $cue->getAlignment() ?? 2;
        $timeIn    = $this->timeCode($cue->getStart());
        $timeOut   = $this->timeCode($cue->getEnd());

        $position = [$stored["verticalPosition"] ?? -1, $stored["justificationCode"] ?? -1];
        if (!isset($stored["verticalPosition"], $stored["justificationCode"]) ||
            Stl::alignment($position[0], $position[1], $this->maxRow) !== $alignment) {
            $position = $this->position($alignment, max(1, count($cue->getLines())));
        }

        $header = $this->header($stored["subtitleGroupNumber"] ?? 0, $stored["cumulativeStatus"] ?? 0, $timeIn, $timeOut, ...$position);
        $blocks = array_map("hex2bin", $stored["blocks"] ?? []);
        if ($blocks !== [] && !$this->stripAll && ($stored["text"] ?? null) === $cue->getText()) {
            return $this->patchHeaders($blocks, $header);
        }

        $userData = array_filter($blocks, fn (string $block): bool => ord($block[3]) === Stl::USER_DATA_BLOCK);

        return [...$this->patchHeaders(array_values($userData), $header), ...$this->textBlocks($this->encodeText($cue), $header)];
    }


    /**
     * Writes bytes 0 and 4 to 15 of $header into each block where they differ from the first block,
     * so that unchanged values stay as they are in every block.
     *
     * @param list<string> $blocks
     *
     * @return list<string>
     */
    private function patchHeaders(array $blocks, string $header): array
    {
        if ($blocks === []) {
            return [];
        }

        $first = $blocks[0];
        foreach ([[0, 1], [4, 1], [5, 4], [9, 4], [13, 1], [14, 1]] as [$offset, $length]) {
            $old = substr($first, $offset, $length);
            $new = substr($header, $offset, $length);
            if ($old === $new) {
                continue;
            }

            foreach ($blocks as $index => $block) {
                if (substr($block, $offset, $length) === $old) {
                    $blocks[$index] = substr_replace($block, $new, $offset, $length);
                }
            }
        }

        return $blocks;
    }


    /**
     * Splits text bytes into text fields. Each field holds 112 bytes, and the last one ends with at least one 8Fh code,
     * EBU Tech 3264 section 4.3.2. A diacritical mark stays in the same field as its letter.
     *
     * @return list<string>
     */
    private function textBlocks(string $bytes, string $header): array
    {
        $fields = [];
        while (strlen($bytes) >= Stl::TEXT_FIELD_SIZE) {
            $length = Stl::TEXT_FIELD_SIZE;
            if ($this->characterCodeTable === "00" && isset(Iso6937::DIACRITICS[ord($bytes[$length - 1])])) {
                $length--;
            }

            $fields[] = str_pad(substr($bytes, 0, $length), Stl::TEXT_FIELD_SIZE, chr(Stl::UNUSED_SPACE));
            $bytes    = substr($bytes, $length);
        }
        $fields[] = str_pad($bytes, Stl::TEXT_FIELD_SIZE, chr(Stl::UNUSED_SPACE));

        if (count($fields) > self::MAX_EXTENSION_BLOCKS + 1) {
            throw new InvalidArgumentException("The cue text needs more than " . (self::MAX_EXTENSION_BLOCKS + 1) . " TTI blocks.");
        }

        $blocks = [];
        foreach ($fields as $index => $field) {
            $extensionBlockNumber = $index === count($fields) - 1 ? Stl::LAST_BLOCK : $index;
            $blocks[]             = substr_replace($header, chr($extensionBlockNumber), 3, 1) . $field;
        }

        return $blocks;
    }


    /**
     * Returns the 16 header bytes of a TTI block, EBU Tech 3264 table 2. The formatter writes the subtitle number later.
     */
    private function header(int $group, int $cumulativeStatus, string $timeIn, string $timeOut, int $verticalPosition, int $justificationCode, int $commentFlag = 0): string
    {
        return chr($group) . "\0\0" . chr(Stl::LAST_BLOCK) . chr($cumulativeStatus) . $timeIn . $timeOut .
               chr($verticalPosition) . chr($justificationCode) . chr($commentFlag);
    }


    /**
     * Returns the vertical position and the justification code for an alignment: the first row of the subtitle
     * at the top, in the middle or at the bottom of the rows, and left, centred or right text.
     *
     * @return array{int, int}
     */
    private function position(int $alignment, int $lineCount): array
    {
        $firstRow = $this->teletext ? 1 : 0;
        $lastRow  = max($firstRow, $this->maxRow - $lineCount + 1);

        $verticalPosition = match (intdiv($alignment - 1, 3)) {
            2       => $firstRow,
            1       => intdiv($firstRow + $lastRow, 2),
            default => $lastRow,
        };

        return [$verticalPosition, ($alignment - 1) % 3 + 1];
    }


    /**
     * Returns the 4 time code bytes hours, minutes, seconds and frames, EBU Tech 3264 section 4.3.2.
     */
    private function timeCode(float $seconds): string
    {
        $frames = $this->frameRate->secondsToFrames(max(0.0, $seconds + $this->offset));
        $fps    = (int) $this->frameRate->getFps();

        return chr(min(intdiv($frames, 3600 * $fps), 0xFF)) . chr(intdiv($frames, 60 * $fps) % 60) .
               chr(intdiv($frames, $fps) % 60) . chr($frames % $fps);
    }


    /**
     * Converts the core markup of the cue lines to text field codes: 80h to 83h for italics and underline,
     * and the teletext alpha colours for the eight colours of EBU Tech 3264 appendix 2.
     */
    private function encodeText(SubtitleCue $cue): string
    {
        $rows = [];
        foreach ($cue->getLines() as $line) {
            $bytes   = "";
            $italic  = 0;
            $under   = 0;
            $colours = [];
            foreach (preg_split('/(<[^>]*>)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $token) {
                if ($token[0] !== "<") {
                    $bytes .= $this->encodeCharacters(Markup::decodeEntities($token));
                    continue;
                }

                if ($this->stripAll) {
                    continue;
                }

                $tag = strtolower($token);
                if (preg_match('/^<font\s+color\s*=\s*["\']?(#[0-9a-f]{6})["\']?\s*>$/', $tag, $matches)) {
                    $colour    = array_search($matches[1], Stl::COLOURS, true);
                    $colours[] = $colour === false ? end($colours) : $colour;
                    $bytes    .= $colour === false ? "" : chr($colour);
                    continue;
                }

                $bytes .= match ($tag) {
                    "<i>"     => $italic++ === 0 ? chr(Stl::ITALICS_ON) : "",
                    "</i>"    => $italic > 0 && --$italic === 0 ? chr(Stl::ITALICS_OFF) : "",
                    "<u>"     => $under++ === 0 ? chr(Stl::UNDERLINE_ON) : "",
                    "</u>"    => $under > 0 && --$under === 0 ? chr(Stl::UNDERLINE_OFF) : "",
                    "</font>" => $this->closeColour($colours),
                    default   => "",
                };
            }

            // A teletext colour code shows as a space, so it replaces one space next to it. At the row end it has no effect.
            $bytes  = rtrim(preg_replace('/ ([\x00-\x07])|([\x00-\x07]) /', '$1$2', $bytes), "\x00..\x07");
            $rows[] = $bytes . ($italic > 0 ? chr(Stl::ITALICS_OFF) : "") . ($under > 0 ? chr(Stl::UNDERLINE_OFF) : "");
        }

        return implode(chr(Stl::NEW_LINE), $rows);
    }


    /**
     * Returns the colour code that applies after a </font> tag, or "" when the colour stays the same.
     */
    private function closeColour(array &$colours): string
    {
        $closed  = array_pop($colours);
        $current = end($colours);
        $current = $current === false ? array_search("#ffffff", Stl::COLOURS, true) : $current;

        return $closed === null || $closed === false || $closed === $current ? "" : chr($current);
    }


    private function encodeCharacters(string $text): string
    {
        $table = Stl::CHARACTER_CODE_TABLES[$this->characterCodeTable];

        return $table === null ? Iso6937::encode($text) : CodePage::encode($text, $table);
    }


    /**
     * Writes the GSI block. The title and language metadata and the counts of the written blocks replace
     * the stored values when they differ from what the parser read.
     *
     * @param list<array{blocks: list<string>, comment: bool}> $sets
     */
    private function formatGsi(Subtitle $subtitle, array $gsi, array $data, array $sets): string
    {
        $subtitles = array_values(array_filter($sets, fn (array $set): bool => !$set["comment"]));
        $counts    = [
            "TNB" => array_sum(array_map(fn (array $set): int => count($set["blocks"]), $sets)),
            "TNS" => count($subtitles),
            "TNG" => count(array_unique(array_map(fn (array $set): int => ord($set["blocks"][0][0]), $subtitles))),
            "TCF" => $subtitles === [] ? "00000000" : Stl::timeCodeDigits(substr($subtitles[0]["blocks"][0], 5, 4)),
        ];
        $formats = ["TNB" => "%05d", "TNS" => "%05d", "TNG" => "%03d", "TCF" => "%s"];
        foreach ($counts as $field => $count) {
            if (!isset($gsi[$field]) || $count !== ($data["counts"][$field] ?? null)) {
                $gsi[$field] = sprintf($formats[$field], $count);
            }
        }

        $title = $subtitle->getMetadata(Subtitle::METADATA_TITLE) ?? "";
        if ($title !== rtrim($gsi["OPT"] ?? "", " \0")) {
            $gsi["OPT"] = $title;
        }

        $language = $subtitle->getMetadata(Subtitle::METADATA_LANGUAGE);
        if (!isset($gsi["LC"]) || $language !== (Stl::LANGUAGES[strtoupper($gsi["LC"])] ?? null)) {
            $gsi["LC"] = $this->languageCode($language);
        }

        $gsi["CD"] ??= gmdate("ymd");
        $gsi["RD"] ??= $gsi["CD"];

        $codePage = Stl::GSI_CODE_PAGES[$gsi["CPN"]] ?? CodePage::CP_850;
        $block    = "";
        foreach (Stl::GSI_FIELDS as $field => [, $length]) {
            $block .= substr(str_pad(CodePage::encode($gsi[$field] ?? "", $codePage), $length), 0, $length);
        }

        return $block;
    }


    private function languageCode(?string $language): string
    {
        if ($language === null) {
            return "00";
        }

        $languages = array_map("strtolower", Stl::LANGUAGES);
        $code      = array_search(strtolower($language), $languages, true)
                     ?: array_search(strtolower(explode("-", $language)[0]), $languages, true);

        return $code === false ? "00" : (string) $code;
    }
}
