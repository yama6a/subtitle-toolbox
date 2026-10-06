<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\CommentAnchors;
use SubtitleToolbox\Encoding\CodePage;
use SubtitleToolbox\Encoding\Iso6937;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\EbuStlReadOptions;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\StyleRuns;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * Reads EBU STL files as defined in EBU Tech 3264: https://tech.ebu.ch/docs/tech/tech3264.pdf
 */
final class EbuStlParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::EbuStl->value;

    protected const BINARY = true;


    protected static function formatOptionsClass(): string
    {
        return EbuStlReadOptions::class;
    }


    protected function read(string $rawSubtitle): Subtitle
    {
        if (strlen($rawSubtitle) < EbuStl::GSI_BLOCK_SIZE) {
            throw new ParsingException("An EBU STL file starts with a GSI block of " . EbuStl::GSI_BLOCK_SIZE . " bytes.");
        }

        try {
            self::checkTtiBlockSize($rawSubtitle);
        } catch (ParsingException $exception) {
            $complete    = intdiv(strlen($rawSubtitle) - EbuStl::GSI_BLOCK_SIZE, EbuStl::TTI_BLOCK_SIZE);
            $cutLength   = EbuStl::GSI_BLOCK_SIZE + $complete * EbuStl::TTI_BLOCK_SIZE;
            $this->fail($exception, null, $complete, [bin2hex(substr($rawSubtitle, $cutLength))]);
            $rawSubtitle = substr($rawSubtitle, 0, $cutLength);
        }

        $gsi = EbuStl::readGsi(substr($rawSubtitle, 0, EbuStl::GSI_BLOCK_SIZE));
        if (!isset(EbuStl::FRAME_RATES[$gsi["DFC"]])) {
            throw new ParsingException("The disk format code \"{$gsi["DFC"]}\" is not STL25.01 or STL30.01.");
        }

        if (!array_key_exists($gsi["CCT"], EbuStl::CHARACTER_CODE_TABLES)) {
            throw new ParsingException("The character code table \"{$gsi["CCT"]}\" is not 00, 01, 02, 03 or 04.");
        }

        $frameRate = new FrameRate(EbuStl::FRAME_RATES[$gsi["DFC"]]);
        $offset    = $this->formatOptions()->subtractStartOfProgramme ? EbuStl::timeCodeToSeconds($gsi["TCP"], $frameRate) : 0.0;
        $sets      = self::readSubtitleSets(substr($rawSubtitle, EbuStl::GSI_BLOCK_SIZE));
        $maxRow    = EbuStl::maxRow($gsi);

        $subtitle   = new Subtitle();
        $parsedCues = [];
        $title      = rtrim($gsi["OPT"], " \0");
        $subtitle->setMetadata(Subtitle::METADATA_TITLE, $title === "" ? null : $title);
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, EbuStl::LANGUAGES[strtoupper($gsi["LC"])] ?? null);

        $comments    = [];
        $cueComments = [];
        $groups      = [];
        $firstTimeIn = null;
        $blockIndex  = 0;
        foreach ($sets as $blocks) {
            $header      = $blocks[0];
            $blockIndex += count($blocks);
            $lines  = self::decodeLines(self::textBytes($blocks), $gsi["CCT"]);
            $hexes  = array_map("bin2hex", $blocks);
            if (ord($header[15]) === 1) {
                $lines = array_filter(array_map("trim", $lines), fn (string $line): bool => $line !== "");
                $text  = Markup::plainText(implode("\n", $lines));
                $cueComments[] = [$text, count($parsedCues)];
                $comments[] = ["text" => $text, "blocks" => $hexes];
                continue;
            }

            if ($this->options->lenient && !self::hasValidTimeCodes($header, $frameRate)) {
                $this->warn(
                    "Subtitle number " . unpack("v", $header, 1)[1] . " has a time code that is not valid: " .
                    EbuStl::timeCodeDigits(substr($header, 5, 4)) . " to " . EbuStl::timeCodeDigits(substr($header, 9, 4)),
                    null,
                    $blockIndex - count($blocks),
                    $hexes,
                    ParseWarningAction::Skipped
                );
                continue;
            }

            $groups[ord($header[0])] = true;
            $firstTimeIn ??= EbuStl::timeCodeDigits(substr($header, 5, 4));

            $start = max(0.0, self::timeCodeBytesToSeconds(substr($header, 5, 4), $frameRate) - $offset);
            $end   = max(0.0, self::timeCodeBytesToSeconds(substr($header, 9, 4), $frameRate) - $offset);
            $cue   = new SubtitleCue($start, $end, $lines);
            $cue->setAlignment(EbuStl::alignment(ord($header[13]), ord($header[14]), $maxRow));
            $cue->setFormatData(self::FORMAT_DATA_KEY, [
                "subtitleGroupNumber" => ord($header[0]),
                "cumulativeStatus"    => ord($header[4]),
                "verticalPosition"    => ord($header[13]),
                "justificationCode"   => ord($header[14]),
                "text"                => $cue->getText(),
                "blocks"              => $hexes,
            ]);
            $parsedCues[] = $cue;
        }
        CommentAnchors::addParsed($subtitle, $parsedCues, $cueComments);

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, [
            "gsi"                        => $gsi,
            "startOfProgrammeSubtracted" => $this->formatOptions()->subtractStartOfProgramme,
            "firstSubtitleNumber"        => $sets === [] ? null : unpack("v", $sets[0][0], 1)[1],
            "comments"                   => $comments,
            "counts"                     => [
                "TNB" => intdiv(strlen($rawSubtitle) - EbuStl::GSI_BLOCK_SIZE, EbuStl::TTI_BLOCK_SIZE),
                "TNS" => count($subtitle->getCues()),
                "TNG" => count($groups),
                "TCF" => $firstTimeIn ?? "00000000",
            ],
        ]);

        return $subtitle;
    }


    private static function checkTtiBlockSize(string $rawSubtitle): void
    {
        if ((strlen($rawSubtitle) - EbuStl::GSI_BLOCK_SIZE) % EbuStl::TTI_BLOCK_SIZE !== 0) {
            throw new ParsingException("The TTI blocks of an EBU STL file must have " . EbuStl::TTI_BLOCK_SIZE . " bytes each.");
        }
    }


    // EBU Tech 3264 limits the TCI and TCO fields to hours 0 to 23, minutes and seconds 0 to 59, and frames below the frame rate.
    private static function hasValidTimeCodes(string $header, FrameRate $frameRate): bool
    {
        foreach ([5, 9] as $offset) {
            [$hours, $minutes, $seconds, $frames] = array_map("ord", str_split(substr($header, $offset, 4)));
            if ($hours > 23 || $minutes > 59 || $seconds > 59 || $frames >= $frameRate->getFramesPerSecond()) {
                return false;
            }
        }

        return true;
    }


    private static function timeCodeBytesToSeconds(string $bytes, FrameRate $frameRate): float
    {
        [$hours, $minutes, $seconds, $frames] = array_map("ord", str_split($bytes));

        return Timecode::toSecondsFromFrames($hours, $minutes, $seconds, $frames, $frameRate);
    }


    /**
     * Groups the TTI blocks into the sets of one subtitle: consecutive blocks with the same subtitle number,
     * up to the block with extension block number FFh. EBU Tech 3264 section 4.3.2.
     *
     * @return list<list<string>>
     */
    private static function readSubtitleSets(string $ttiBlocks): array
    {
        $sets    = [];
        $current = [];
        foreach (str_split($ttiBlocks, EbuStl::TTI_BLOCK_SIZE) as $block) {
            if ($block === "") {
                continue;
            }

            if ($current !== [] && substr($current[0], 1, 2) !== substr($block, 1, 2)) {
                $sets[]  = $current;
                $current = [];
            }

            $current[] = $block;
            if (ord($block[3]) === EbuStl::LAST_BLOCK) {
                $sets[]  = $current;
                $current = [];
            }
        }

        if ($current !== []) {
            $sets[] = $current;
        }

        return $sets;
    }


    /**
     * Joins the text fields of the blocks that carry subtitle text, EBN 00h to EFh and FFh, without the unused space code.
     */
    private static function textBytes(array $blocks): string
    {
        $text = "";
        foreach ($blocks as $block) {
            $extensionBlockNumber = ord($block[3]);
            if ($extensionBlockNumber <= 0xEF || $extensionBlockNumber === EbuStl::LAST_BLOCK) {
                $text .= substr($block, 16, EbuStl::TEXT_FIELD_SIZE);
            }
        }

        return str_replace(chr(EbuStl::UNUSED_SPACE), "", $text);
    }


    /**
     * Converts a text field to cue lines with core markup. EBU Tech 3264 section 5 lists the control codes.
     * A teletext control code takes the place of a space. Italics and underline last until their off code,
     * and the color returns to white at each new row.
     *
     * @return list<string>
     */
    private static function decodeLines(string $bytes, string $characterCodeTable): array
    {
        $lines = [];
        $runs  = [];
        $style = ["color" => EbuStl::WHITE, "i" => false, "u" => false];
        $text  = "";
        foreach (str_split($bytes) as $byte) {
            $code = ord($byte);
            if (($code >= 0x20 && $code < 0x80) || $code >= 0xA0) {
                $text .= $byte;
                continue;
            }

            $runs[] = [self::decodeCharacters($text, $characterCodeTable), self::runStyle($style)];
            $text   = "";
            if ($code < 0x20) {
                $runs[] = [" ", []];
            }

            match (true) {
                $code < 0x08                    => $style["color"] = $code,
                $code === EbuStl::ITALICS_ON    => $style["i"] = true,
                $code === EbuStl::ITALICS_OFF   => $style["i"] = false,
                $code === EbuStl::UNDERLINE_ON  => $style["u"] = true,
                $code === EbuStl::UNDERLINE_OFF => $style["u"] = false,
                default                         => null,
            };

            if ($code === EbuStl::NEW_LINE) {
                $lines[]        = StyleRuns::toMarkup($runs, true);
                $runs           = [];
                $style["color"] = EbuStl::WHITE;
            }
        }

        $runs[]  = [self::decodeCharacters($text, $characterCodeTable), self::runStyle($style)];
        $lines[] = StyleRuns::toMarkup($runs, true);

        return $lines;
    }


    private static function decodeCharacters(string $bytes, string $characterCodeTable): string
    {
        $table = EbuStl::CHARACTER_CODE_TABLES[$characterCodeTable];

        return $table === null ? Iso6937::decode($bytes) : CodePage::decode($bytes, $table);
    }


    /**
     * @param array{color: int, i: bool, u: bool} $style
     * @return array{color: ?string, i: bool, u: bool}
     */
    private static function runStyle(array $style): array
    {
        return ["color" => $style["color"] === EbuStl::WHITE ? null : EbuStl::COLORS[$style["color"]]] + $style;
    }
}
