<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Encoding\CodePage;
use SubtitleToolbox\Encoding\Iso6937;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Formatters\Options\EbuStlWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\EbuStl;
use SubtitleToolbox\Parsers\EbuStlParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

/**
 * Writes EBU STL files as defined in EBU Tech 3264: https://tech.ebu.ch/docs/tech/tech3264.pdf
 */
final class EbuStlFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = EbuStlWriteOptions::class;

    // The values of a new GSI block. EBU Tech 3264 section 4.2.5 fills unused bytes with spaces.
    private const DEFAULT_GSI = [
        "CPN" => "850", "DSC" => "1", "CCT" => "00", "RN" => "00", "MNC" => "40", "MNR" => "23",
        "TCS" => "1", "TCP" => "00000000", "TND" => "1", "DSN" => "1",
    ];

    private const FIRST_SUBTITLE_NUMBER = 1;
    private const MAX_EXTENSION_BLOCKS  = 0xF0;


    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $data = $subtitle->findFormatData(EbuStlParser::FORMAT_DATA_KEY);
        $gsi  = ($data["gsi"] ?? []) + self::DEFAULT_GSI;
        $fps  = $this->formatOptions($options)?->frameRate ?? EbuStl::FRAME_RATES[$gsi["DFC"] ?? ""] ?? 25;

        if (!array_key_exists($gsi["CCT"], EbuStl::CHARACTER_CODE_TABLES)) {
            throw new InvalidArgumentException("The character code table \"{$gsi["CCT"]}\" is not 00, 01, 02, 03 or 04.");
        }

        $gsi["DFC"] = array_search((int) $fps, EbuStl::FRAME_RATES, true);
        $frameRate  = new FrameRate($fps);
        $context    = new EbuStlContext(
            frameRate: $frameRate,
            offset: empty($data["startOfProgrammeSubtracted"]) ? 0.0 : EbuStl::timeCodeToSeconds($gsi["TCP"], $frameRate),
            characterCodeTable: $gsi["CCT"],
            maxRow: EbuStl::maxRow($gsi),
            teletext: in_array($gsi["DSC"], ["1", "2"], true),
            stripAll: $options->stripTags,
        );

        $sets = $this->subtitleSets($context, $subtitle, $data["comments"] ?? []);

        $number = $data["firstSubtitleNumber"] ?? self::FIRST_SUBTITLE_NUMBER;
        $blocks = "";
        foreach ($sets as $set) {
            if ($number > 0xFFFF) {
                throw new UnwritableContentException("EBU STL allows subtitle numbers up to 65535.");
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
    private function subtitleSets(EbuStlContext $context, Subtitle $subtitle, array $storedComments): array
    {
        $cues     = array_values($subtitle->getCues());
        $comments = $subtitle->getComments();
        $sets     = [];
        foreach ([...array_keys($cues), count($cues)] as $index) {
            while ($comments !== [] && $comments[0]->beforeCueIndex <= $index) {
                $timeCode = $this->smpteBytes($context, isset($cues[$index]) ? $cues[$index]->getStart() : (end($cues) ?: new SubtitleCue())->getEnd());
                $sets[]   = ["blocks" => $this->commentBlocks($context, array_shift($comments)->text, $storedComments, $timeCode), "comment" => true];
            }

            if (isset($cues[$index])) {
                $sets[] = ["blocks" => $this->cueBlocks($context, $cues[$index]), "comment" => false];
            }
        }

        return $sets;
    }


    /**
     * Writes the stored blocks of a comment with the same text, or new blocks with the comment flag set.
     *
     * @return list<string>
     */
    private function commentBlocks(EbuStlContext $context, string $text, array &$storedComments, string $smpteBytes): array
    {
        foreach ($storedComments as $index => $stored) {
            if ($stored["text"] === $text) {
                unset($storedComments[$index]);

                return array_map("hex2bin", $stored["blocks"]);
            }
        }

        $bytes = implode(chr(EbuStl::NEW_LINE), array_map(fn (string $line): string => $this->encodeCharacters($context, $line), explode("\n", $text)));
        [$verticalPosition, $justificationCode] = $this->position($context, 2, count(explode("\n", $text)));

        return $this->textBlocks($context, $bytes, $this->header(0, 0, $smpteBytes, $smpteBytes, $verticalPosition, $justificationCode, 1));
    }


    /**
     * Writes the stored blocks of an unchanged cue with new times and position, or new blocks from the core markup.
     *
     * @return list<string>
     */
    private function cueBlocks(EbuStlContext $context, SubtitleCue $cue): array
    {
        $stored    = $cue->findFormatData(EbuStlParser::FORMAT_DATA_KEY);
        $alignment = $cue->getAlignment() ?? 2;
        $timeIn    = $this->smpteBytes($context, $cue->getStart());
        $timeOut   = $this->smpteBytes($context, $cue->getEnd());

        $position = [$stored["verticalPosition"] ?? -1, $stored["justificationCode"] ?? -1];
        if (!isset($stored["verticalPosition"], $stored["justificationCode"]) ||
            EbuStl::alignment($position[0], $position[1], $context->maxRow) !== $alignment) {
            $position = $this->position($context, $alignment, max(1, count($cue->getLines())));
        }

        $header = $this->header($stored["subtitleGroupNumber"] ?? 0, $stored["cumulativeStatus"] ?? 0, $timeIn, $timeOut, ...$position);
        $blocks = array_map("hex2bin", $stored["blocks"] ?? []);
        if ($blocks !== [] && !$context->stripAll && ($stored["text"] ?? null) === $cue->getText()) {
            return $this->patchHeaders($blocks, $header);
        }

        $userData = array_filter($blocks, fn (string $block): bool => ord($block[3]) === EbuStl::USER_DATA_BLOCK);

        return [...$this->patchHeaders(array_values($userData), $header), ...$this->textBlocks($context, $this->encodeText($context, $cue), $header)];
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
    private function textBlocks(EbuStlContext $context, string $bytes, string $header): array
    {
        $fields = [];
        while (strlen($bytes) >= EbuStl::TEXT_FIELD_SIZE) {
            $length = EbuStl::TEXT_FIELD_SIZE;
            if ($context->characterCodeTable === "00" && isset(Iso6937::DIACRITICS[ord($bytes[$length - 1])])) {
                $length--;
            }

            $fields[] = str_pad(substr($bytes, 0, $length), EbuStl::TEXT_FIELD_SIZE, chr(EbuStl::UNUSED_SPACE));
            $bytes    = substr($bytes, $length);
        }
        $fields[] = str_pad($bytes, EbuStl::TEXT_FIELD_SIZE, chr(EbuStl::UNUSED_SPACE));

        if (count($fields) > self::MAX_EXTENSION_BLOCKS + 1) {
            throw new UnwritableContentException("The cue text needs more than " . (self::MAX_EXTENSION_BLOCKS + 1) . " TTI blocks.");
        }

        $blocks = [];
        foreach ($fields as $index => $field) {
            $extensionBlockNumber = $index === count($fields) - 1 ? EbuStl::LAST_BLOCK : $index;
            $blocks[]             = substr_replace($header, chr($extensionBlockNumber), 3, 1) . $field;
        }

        return $blocks;
    }


    /**
     * Returns the 16 header bytes of a TTI block, EBU Tech 3264 table 2. The formatter writes the subtitle number later.
     */
    private function header(int $group, int $cumulativeStatus, string $tci, string $tco, int $verticalPosition, int $justificationCode, int $commentFlag = 0): string
    {
        return chr($group) . "\0\0" . chr(EbuStl::LAST_BLOCK) . chr($cumulativeStatus) . $tci . $tco .
               chr($verticalPosition) . chr($justificationCode) . chr($commentFlag);
    }


    /**
     * Returns the vertical position and the justification code for an alignment: the first row of the subtitle
     * at the top, in the middle or at the bottom of the rows, and left, centred or right text.
     *
     * @return array{int, int}
     */
    private function position(EbuStlContext $context, int $alignment, int $lineCount): array
    {
        $firstRow = $context->teletext ? 1 : 0;
        $lastRow  = max($firstRow, $context->maxRow - $lineCount + 1);

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
    private function smpteBytes(EbuStlContext $context, float $seconds): string
    {
        [$hours, $minutes, $wholeSeconds, $frames] = Timecode::frames(max(0.0, $seconds + $context->offset), $context->frameRate);

        return chr(min($hours, 0xFF)) . chr($minutes) . chr($wholeSeconds) . chr($frames);
    }


    /**
     * Converts the core markup of the cue lines to text field codes: 80h to 83h for italics and underline,
     * and the teletext alpha colors for the eight colors of EBU Tech 3264 appendix 2.
     */
    private function encodeText(EbuStlContext $context, SubtitleCue $cue): string
    {
        $rows = [];
        foreach ($cue->getLines() as $line) {
            $bytes   = "";
            $italic  = 0;
            $under   = 0;
            $colors = [];
            foreach (preg_split('/(<[^>]*>)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $token) {
                if ($token[0] !== "<") {
                    $bytes .= $this->encodeCharacters($context, Markup::decodeEntities($token));
                    continue;
                }

                if ($context->stripAll) {
                    continue;
                }

                $tag = strtolower($token);
                if (preg_match('/^<font\s+color\s*=\s*["\']?(#[0-9a-f]{6})["\']?\s*>$/', $tag, $matches)) {
                    $color    = array_search($matches[1], EbuStl::COLORS, true);
                    $colors[] = $color === false ? end($colors) : $color;
                    $bytes    .= $color === false ? "" : chr($color);
                    continue;
                }

                $bytes .= match ($tag) {
                    "<i>"     => $italic++ === 0 ? chr(EbuStl::ITALICS_ON) : "",
                    "</i>"    => $italic > 0 && --$italic === 0 ? chr(EbuStl::ITALICS_OFF) : "",
                    "<u>"     => $under++ === 0 ? chr(EbuStl::UNDERLINE_ON) : "",
                    "</u>"    => $under > 0 && --$under === 0 ? chr(EbuStl::UNDERLINE_OFF) : "",
                    "</font>" => $this->closeColor($colors),
                    default   => "",
                };
            }

            // A teletext color code shows as a space, so it replaces one space next to it. At the row end it has no effect.
            $bytes  = rtrim(preg_replace('/ ([\x00-\x07])|([\x00-\x07]) /', '$1$2', $bytes), "\x00..\x07");
            $rows[] = $bytes . ($italic > 0 ? chr(EbuStl::ITALICS_OFF) : "") . ($under > 0 ? chr(EbuStl::UNDERLINE_OFF) : "");
        }

        return implode(chr(EbuStl::NEW_LINE), $rows);
    }


    /**
     * Returns the color code that applies after a </font> tag, or "" when the color stays the same.
     */
    private function closeColor(array &$colors): string
    {
        $closed  = array_pop($colors);
        $current = end($colors);
        $current = $current === false ? array_search("#ffffff", EbuStl::COLORS, true) : $current;

        return $closed === null || $closed === false || $closed === $current ? "" : chr($current);
    }


    private function encodeCharacters(EbuStlContext $context, string $text): string
    {
        $table = EbuStl::CHARACTER_CODE_TABLES[$context->characterCodeTable];

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
            "TCF" => $subtitles === [] ? "00000000" : EbuStl::timeCodeDigits(substr($subtitles[0]["blocks"][0], 5, 4)),
        ];
        $formats = ["TNB" => "%05d", "TNS" => "%05d", "TNG" => "%03d", "TCF" => "%s"];
        foreach ($counts as $field => $count) {
            if (!isset($gsi[$field]) || $count !== ($data["counts"][$field] ?? null)) {
                $gsi[$field] = sprintf($formats[$field], $count);
            }
        }

        $title = $subtitle->findMetadata(Subtitle::METADATA_TITLE) ?? "";
        if ($title !== rtrim($gsi["OPT"] ?? "", " \0")) {
            $gsi["OPT"] = $title;
        }

        $language = $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE);
        if (!isset($gsi["LC"]) || $language !== (EbuStl::LANGUAGES[strtoupper($gsi["LC"])] ?? null)) {
            $gsi["LC"] = $this->languageCode($language);
        }

        $gsi["CD"] ??= gmdate("ymd");
        $gsi["RD"] ??= $gsi["CD"];

        $codePage = EbuStl::GSI_CODE_PAGES[$gsi["CPN"]] ?? CodePage::CP_850;
        $block    = "";
        foreach (EbuStl::GSI_FIELDS as $field => [, $length]) {
            $block .= substr(str_pad(CodePage::encode($gsi[$field] ?? "", $codePage), $length), 0, $length);
        }

        return $block;
    }


    private function languageCode(?string $language): string
    {
        if ($language === null) {
            return "00";
        }

        $languages = array_map("strtolower", EbuStl::LANGUAGES);
        $code      = array_search(strtolower($language), $languages, true)
                     ?: array_search(strtolower(explode("-", $language)[0]), $languages, true);

        return $code === false ? "00" : (string) $code;
    }
}
