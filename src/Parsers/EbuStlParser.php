<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Encoding\CodePage;
use SubtitleToolbox\Encoding\Iso6937;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Reads EBU STL files as defined in EBU Tech 3264: https://tech.ebu.ch/docs/tech/tech3264.pdf
 */
class EbuStlParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = "stl";

    public const GSI_BLOCK_SIZE = 1024;
    public const TTI_BLOCK_SIZE = 128;
    public const TEXT_FIELD_SIZE = 112;

    // Offset and length of each GSI field by its mnemonic, EBU Tech 3264 table 1. The spare bytes have no mnemonic.
    public const GSI_FIELDS = [
        "CPN" => [0, 3], "DFC" => [3, 8], "DSC" => [11, 1], "CCT" => [12, 2], "LC" => [14, 2],
        "OPT" => [16, 32], "OET" => [48, 32], "TPT" => [80, 32], "TET" => [112, 32], "TN" => [144, 32],
        "TCD" => [176, 32], "SLR" => [208, 16], "CD" => [224, 6], "RD" => [230, 6], "RN" => [236, 2],
        "TNB" => [238, 5], "TNS" => [243, 5], "TNG" => [248, 3], "MNC" => [251, 2], "MNR" => [253, 2],
        "TCS" => [255, 1], "TCP" => [256, 8], "TCF" => [264, 8], "TND" => [272, 1], "DSN" => [273, 1],
        "CO" => [274, 3], "PUB" => [277, 32], "EN" => [309, 32], "ECD" => [341, 32], "spare" => [373, 75],
        "UDA" => [448, 576],
    ];

    public const FRAME_RATES = ["STL25.01" => 25, "STL30.01" => 30];

    // EBU Tech 3264 section 4.2.2 and appendix 1.
    public const GSI_CODE_PAGES = [
        "437" => CodePage::CP_437,
        "850" => CodePage::CP_850,
        "860" => CodePage::CP_860,
        "863" => CodePage::CP_863,
        "865" => CodePage::CP_865,
    ];

    // EBU Tech 3264 section 4.2.2 and appendix 2. Table 00 is ISO 6937.
    public const CHARACTER_CODE_TABLES = [
        "00" => null,
        "01" => CodePage::ISO_8859_5,
        "02" => CodePage::ISO_8859_6,
        "03" => CodePage::ISO_8859_7,
        "04" => CodePage::ISO_8859_8,
    ];

    // The language codes of EBU Tech 3264 appendix 3 as BCP 47 tags. Codes without a tag are left out.
    public const LANGUAGES = [
        "01" => "sq", "02" => "br", "03" => "ca", "04" => "hr", "05" => "cy", "06" => "cs", "07" => "da", "08" => "de",
        "09" => "en", "0A" => "es", "0B" => "eo", "0C" => "et", "0D" => "eu", "0E" => "fo", "0F" => "fr", "10" => "fy",
        "11" => "ga", "12" => "gd", "13" => "gl", "14" => "is", "15" => "it", "16" => "se", "17" => "la", "18" => "lv",
        "19" => "lb", "1A" => "lt", "1B" => "hu", "1C" => "mt", "1D" => "nl", "1E" => "no", "1F" => "oc", "20" => "pl",
        "21" => "pt", "22" => "ro", "23" => "rm", "24" => "sr", "25" => "sk", "26" => "sl", "27" => "fi", "28" => "sv",
        "29" => "tr", "2A" => "nl-BE", "2B" => "wa",
        "45" => "zu", "46" => "vi", "47" => "uz", "48" => "ur", "49" => "uk", "4A" => "th", "4B" => "te", "4C" => "tt",
        "4D" => "ta", "4E" => "tg", "4F" => "sw", "50" => "srn", "51" => "so", "52" => "si", "53" => "sn", "54" => "sh",
        "55" => "rue", "56" => "ru", "57" => "qu", "58" => "ps", "59" => "pa", "5A" => "fa", "5B" => "pap", "5C" => "or",
        "5D" => "ne", "5E" => "nd", "5F" => "mr", "60" => "mo", "61" => "ms", "62" => "mg", "63" => "mk", "64" => "lo",
        "65" => "ko", "66" => "km", "67" => "kk", "68" => "kn", "69" => "ja", "6A" => "id", "6B" => "hi", "6C" => "he",
        "6D" => "ha", "6E" => "gn", "6F" => "gu", "70" => "el", "71" => "ka", "72" => "ff", "73" => "prs", "74" => "cv",
        "75" => "zh", "76" => "my", "77" => "bg", "78" => "bn", "79" => "be", "7A" => "bm", "7B" => "az", "7C" => "as",
        "7D" => "hy", "7E" => "ar", "7F" => "am",
    ];

    // The teletext alpha colour codes 00h to 07h, EBU Tech 3264 appendix 2. White is the default of each row.
    public const COLOURS = ["#000000", "#ff0000", "#00ff00", "#ffff00", "#0000ff", "#ff00ff", "#00ffff", "#ffffff"];

    public const ITALICS_ON      = 0x80;
    public const ITALICS_OFF     = 0x81;
    public const UNDERLINE_ON    = 0x82;
    public const UNDERLINE_OFF   = 0x83;
    public const NEW_LINE        = 0x8A;
    public const UNUSED_SPACE    = 0x8F;
    public const LAST_BLOCK      = 0xFF;
    public const USER_DATA_BLOCK = 0xFE;

    private const WHITE = 7;

    private bool $subtractStartOfProgramme;


    /**
     * Subtracts the start-of-programme time code of the GSI block from all cue times when $subtractStartOfProgramme is true.
     */
    public function __construct(bool $subtractStartOfProgramme = false)
    {
        $this->subtractStartOfProgramme = $subtractStartOfProgramme;
    }


    public function parse(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        if (strlen($rawSubtitle) < self::GSI_BLOCK_SIZE) {
            throw new ParsingException("An EBU STL file starts with a GSI block of " . self::GSI_BLOCK_SIZE . " bytes.");
        }

        try {
            self::checkTtiBlockSize($rawSubtitle);
        } catch (ParsingException $exception) {
            $complete    = intdiv(strlen($rawSubtitle) - self::GSI_BLOCK_SIZE, self::TTI_BLOCK_SIZE);
            $cutLength   = self::GSI_BLOCK_SIZE + $complete * self::TTI_BLOCK_SIZE;
            $this->fail($exception, 0, $complete, [bin2hex(substr($rawSubtitle, $cutLength))]);
            $rawSubtitle = substr($rawSubtitle, 0, $cutLength);
        }

        $gsi = self::readGsi(substr($rawSubtitle, 0, self::GSI_BLOCK_SIZE));
        if (!isset(self::FRAME_RATES[$gsi["DFC"]])) {
            throw new ParsingException("The disk format code \"{$gsi["DFC"]}\" is not STL25.01 or STL30.01.");
        }

        if (!array_key_exists($gsi["CCT"], self::CHARACTER_CODE_TABLES)) {
            throw new ParsingException("The character code table \"{$gsi["CCT"]}\" is not 00, 01, 02, 03 or 04.");
        }

        $frameRate = new FrameRate(self::FRAME_RATES[$gsi["DFC"]]);
        $offset    = $this->subtractStartOfProgramme ? self::timeCodeToSeconds($gsi["TCP"], $frameRate) : 0.0;
        $sets      = self::readSubtitleSets(substr($rawSubtitle, self::GSI_BLOCK_SIZE));
        $maxRow    = self::maxRow($gsi);

        $subtitle = new Subtitle();
        $title    = rtrim($gsi["OPT"], " \0");
        $subtitle->setMetadata(Subtitle::METADATA_TITLE, $title === "" ? null : $title);
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, self::LANGUAGES[strtoupper($gsi["LC"])] ?? null);

        $comments    = [];
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
                $text  = Markup::decodeEntities(Markup::stripAllTags(implode("\n", $lines)));
                $subtitle->addComment($text, count($subtitle->getCues()));
                $comments[] = ["text" => $text, "blocks" => $hexes];
                continue;
            }

            if ($this->lenient && !self::hasValidTimeCodes($header, $frameRate)) {
                $this->warn(
                    "Subtitle number " . unpack("v", $header, 1)[1] . " has a time code that is not valid: " .
                    self::timeCodeDigits(substr($header, 5, 4)) . " to " . self::timeCodeDigits(substr($header, 9, 4)),
                    0,
                    $blockIndex - count($blocks),
                    $hexes,
                    ParseWarning::SKIPPED
                );
                continue;
            }

            $groups[ord($header[0])] = true;
            $firstTimeIn ??= self::timeCodeDigits(substr($header, 5, 4));

            $start = max(0.0, self::timeCodeBytesToSeconds(substr($header, 5, 4), $frameRate) - $offset);
            $end   = max(0.0, self::timeCodeBytesToSeconds(substr($header, 9, 4), $frameRate) - $offset);
            $cue   = new SubtitleCue($start, $end, $lines);
            $cue->setAlignment(self::alignment(ord($header[13]), ord($header[14]), $maxRow));
            $cue->setFormatData(self::FORMAT_DATA_KEY, [
                "subtitleGroupNumber" => ord($header[0]),
                "cumulativeStatus"    => ord($header[4]),
                "verticalPosition"    => ord($header[13]),
                "justificationCode"   => ord($header[14]),
                "text"                => $cue->getText(),
                "blocks"              => $hexes,
            ]);
            $subtitle->addCue($cue);
        }

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, [
            "gsi"                        => $gsi,
            "startOfProgrammeSubtracted" => $this->subtractStartOfProgramme,
            "firstSubtitleNumber"        => $sets === [] ? null : unpack("v", $sets[0][0], 1)[1],
            "comments"                   => $comments,
            "counts"                     => [
                "TNB" => intdiv(strlen($rawSubtitle) - self::GSI_BLOCK_SIZE, self::TTI_BLOCK_SIZE),
                "TNS" => count($subtitle->getCues()),
                "TNG" => count($groups),
                "TCF" => $firstTimeIn ?? "00000000",
            ],
        ]);

        return $subtitle;
    }


    /**
     * Returns the cue alignment for a vertical position and a justification code. The top, middle and bottom
     * thirds of the rows from 0 to $maxRow give the row of the numeric keypad layout.
     */
    public static function alignment(int $verticalPosition, int $justificationCode, int $maxRow): int
    {
        $column = match ($justificationCode) {
            1       => 0,
            3       => 2,
            default => 1,
        };

        $row = match (true) {
            3 * $verticalPosition < $maxRow     => 6,
            3 * $verticalPosition < 2 * $maxRow => 3,
            default                             => 0,
        };

        return 1 + $row + $column;
    }


    /**
     * Returns the highest vertical position: teletext row 23, or the maximum number of displayable rows for open subtitles.
     */
    public static function maxRow(array $gsi): int
    {
        if (in_array($gsi["DSC"] ?? "", ["1", "2"], true)) {
            return 23;
        }

        $rows = (int) ($gsi["MNR"] ?? 0);

        return $rows > 0 ? $rows : 23;
    }


    /**
     * Returns the GSI fields by mnemonic, decoded with the code page of the CPN field and without trailing spaces.
     *
     * @return array<string, string>
     */
    public static function readGsi(string $block): array
    {
        $codePage = self::GSI_CODE_PAGES[substr($block, 0, 3)] ?? CodePage::CP_850;

        $gsi = [];
        foreach (self::GSI_FIELDS as $field => [$offset, $length]) {
            $gsi[$field] = rtrim(CodePage::decode(substr($block, $offset, $length), $codePage), " ");
        }

        return $gsi;
    }


    /**
     * Returns the seconds of an 8-digit HHMMSSFF time code such as the TCP field, or 0 when it is not 8 digits.
     */
    public static function timeCodeToSeconds(string $timeCode, FrameRate $frameRate): float
    {
        if (!preg_match('/^(\d\d)(\d\d)(\d\d)(\d\d)$/', $timeCode, $matches)) {
            return 0.0;
        }

        return $matches[1] * 3600 + $matches[2] * 60 + $matches[3] + $frameRate->framesToSeconds((int) $matches[4]);
    }


    /**
     * Returns the 4 time code bytes hours, minutes, seconds and frames as an 8-digit HHMMSSFF string.
     */
    public static function timeCodeDigits(string $bytes): string
    {
        return vsprintf("%02d%02d%02d%02d", array_map("ord", str_split($bytes)));
    }


    private static function checkTtiBlockSize(string $rawSubtitle): void
    {
        if ((strlen($rawSubtitle) - self::GSI_BLOCK_SIZE) % self::TTI_BLOCK_SIZE !== 0) {
            throw new ParsingException("The TTI blocks of an EBU STL file must have " . self::TTI_BLOCK_SIZE . " bytes each.");
        }
    }


    // EBU Tech 3264 limits the TCI and TCO fields to hours 0 to 23, minutes and seconds 0 to 59, and frames below the frame rate.
    private static function hasValidTimeCodes(string $header, FrameRate $frameRate): bool
    {
        foreach ([5, 9] as $offset) {
            [$hours, $minutes, $seconds, $frames] = array_map("ord", str_split(substr($header, $offset, 4)));
            if ($hours > 23 || $minutes > 59 || $seconds > 59 || $frames >= $frameRate->getFps()) {
                return false;
            }
        }

        return true;
    }


    private static function timeCodeBytesToSeconds(string $bytes, FrameRate $frameRate): float
    {
        [$hours, $minutes, $seconds, $frames] = array_map("ord", str_split($bytes));

        return $hours * 3600 + $minutes * 60 + $seconds + $frameRate->framesToSeconds($frames);
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
        foreach (str_split($ttiBlocks, self::TTI_BLOCK_SIZE) as $block) {
            if ($block === "") {
                continue;
            }

            if ($current !== [] && substr($current[0], 1, 2) !== substr($block, 1, 2)) {
                $sets[]  = $current;
                $current = [];
            }

            $current[] = $block;
            if (ord($block[3]) === self::LAST_BLOCK) {
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
            if ($extensionBlockNumber <= 0xEF || $extensionBlockNumber === self::LAST_BLOCK) {
                $text .= substr($block, 16, self::TEXT_FIELD_SIZE);
            }
        }

        return str_replace(chr(self::UNUSED_SPACE), "", $text);
    }


    /**
     * Converts a text field to cue lines with core markup. EBU Tech 3264 section 5 lists the control codes.
     * A teletext control code takes the place of a space. Italics and underline last until their off code,
     * and the colour returns to white at each new row.
     *
     * @return list<string>
     */
    private static function decodeLines(string $bytes, string $characterCodeTable): array
    {
        $lines  = [];
        $line   = "";
        $open   = [];
        $wanted = ["colour" => self::WHITE, "i" => false, "u" => false];
        $run    = "";

        $flush = function () use (&$line, &$open, &$wanted, &$run, $characterCodeTable): void {
            $text    = self::decodeCharacters($run, $characterCodeTable);
            $run     = "";
            $spaces  = strspn($text, " ");
            $line   .= substr($text, 0, $spaces);
            if ($spaces === strlen($text)) {
                return;
            }

            [$closing, $opening] = self::syncTags($open, $wanted);
            $line = self::appendClosingTags($line, $closing) . $opening . Markup::escapeText(substr($text, $spaces));
        };

        foreach (str_split($bytes) as $byte) {
            $code = ord($byte);
            if (($code >= 0x20 && $code < 0x80) || $code >= 0xA0) {
                $run .= $byte;
                continue;
            }

            $flush();
            if ($code < 0x20) {
                $line .= " ";
            }

            match (true) {
                $code < 0x08                  => $wanted["colour"] = $code,
                $code === self::ITALICS_ON    => $wanted["i"] = true,
                $code === self::ITALICS_OFF   => $wanted["i"] = false,
                $code === self::UNDERLINE_ON  => $wanted["u"] = true,
                $code === self::UNDERLINE_OFF => $wanted["u"] = false,
                default                       => null,
            };

            if ($code === self::NEW_LINE) {
                $lines[]          = self::appendClosingTags($line, self::closeTags($open));
                $line             = "";
                $open             = [];
                $wanted["colour"] = self::WHITE;
            }
        }

        $flush();
        $lines[] = self::appendClosingTags($line, self::closeTags($open));

        return $lines;
    }


    private static function decodeCharacters(string $bytes, string $characterCodeTable): string
    {
        $table = self::CHARACTER_CODE_TABLES[$characterCodeTable];

        return $table === null ? Iso6937::decode($bytes) : CodePage::decode($bytes, $table);
    }


    /**
     * Closes the open tags that no longer apply and opens the wanted ones. Returns the closing and the opening tags.
     *
     * @param list<string> $open tags such as "i" or "font:#ff0000", outermost first
     *
     * @return array{string, string}
     */
    private static function syncTags(array &$open, array $wanted): array
    {
        $tags = array_keys(array_filter(["i" => $wanted["i"], "u" => $wanted["u"]]));
        if ($wanted["colour"] !== self::WHITE) {
            array_unshift($tags, "font:" . self::COLOURS[$wanted["colour"]]);
        }

        $keep = 0;
        while ($keep < count($open) && in_array($open[$keep], $tags, true)) {
            $keep++;
        }

        $closing = self::closeTags(array_slice($open, $keep));
        $opening = "";
        $open    = array_slice($open, 0, $keep);
        foreach (array_diff($tags, $open) as $tag) {
            $open[]   = $tag;
            $opening .= str_starts_with($tag, "font:") ? "<font color=\"" . substr($tag, 5) . "\">" : "<$tag>";
        }

        return [$closing, $opening];
    }


    /**
     * Appends closing tags before the trailing spaces of $line, so that the spaces stay outside the tags.
     */
    private static function appendClosingTags(string $line, string $closing): string
    {
        $text = rtrim($line, " ");

        return $text . $closing . substr($line, strlen($text));
    }


    private static function closeTags(array $open): string
    {
        return implode("", array_map(
            fn (string $tag): string => str_starts_with($tag, "font:") ? "</font>" : "</$tag>",
            array_reverse($open)
        ));
    }
}
