<?php

// Writes the self-written EBU STL fixtures in real/ byte by byte, without the library code.
// Run it with: php tests/files/stl/generate-fixtures.php

const TELETEXT_ROW_START = "\x0D\x0B\x0B";
const TELETEXT_ROW_END   = "\x0A\x0A";
const NEW_ROW            = "\x8A";


function gsi(array $fields): string
{
    $layout = [
        "CPN" => 3, "DFC" => 8, "DSC" => 1, "CCT" => 2, "LC" => 2, "OPT" => 32, "OET" => 32, "TPT" => 32, "TET" => 32,
        "TN" => 32, "TCD" => 32, "SLR" => 16, "CD" => 6, "RD" => 6, "RN" => 2, "TNB" => 5, "TNS" => 5, "TNG" => 3,
        "MNC" => 2, "MNR" => 2, "TCS" => 1, "TCP" => 8, "TCF" => 8, "TND" => 1, "DSN" => 1, "CO" => 3, "PUB" => 32,
        "EN" => 32, "ECD" => 32, "spare" => 75, "UDA" => 576,
    ];

    $block = "";
    foreach ($layout as $field => $length) {
        $block .= str_pad($fields[$field] ?? "", $length);
    }

    return $block;
}


function tti(int $group, int $number, int $extension, int $cumulative, array $in, array $out, int $vertical, int $justification, int $comment, string $text): string
{
    if (strlen($text) > 112) {
        throw new LengthException("The text field holds 112 bytes, got " . strlen($text) . ".");
    }

    return chr($group) . pack("v", $number) . chr($extension) . chr($cumulative) .
           implode("", array_map("chr", $in)) . implode("", array_map("chr", $out)) .
           chr($vertical) . chr($justification) . chr($comment) . str_pad($text, 112, "\x8F");
}


function teletextRows(string ...$rows): string
{
    return implode(NEW_ROW . NEW_ROW, array_map(fn (string $row): string => TELETEXT_ROW_START . $row . TELETEXT_ROW_END, $rows));
}


function write(string $name, string $gsi, array $blocks): void
{
    file_put_contents(__DIR__ . "/real/$name", $gsi . implode("", $blocks));
}


// Teletext, 25 fps, ISO 6937, start of programme 10:00:00:00. Extension blocks, a comment block and a user data block.
$longText = teletextRows(
    "\x03Our bakery opens at six every morning.",
    "\x03The first loaves come out at seven.",
    "\x03Rye bread and white bread are baked",
    "\x03in the stone oven behind the shop."
);
$bakery = [
    tti(0, 1, 0xFF, 0, [10, 0, 1, 0], [10, 0, 3, 12], 20, 2, 0, teletextRows("The ovens are warm.", "Bread is ready at six.")),
    tti(0, 2, 0xFF, 0, [10, 0, 4, 0], [10, 0, 6, 0], 22, 2, 0, teletextRows("\x01Fresh\x07rolls are \x80warm\x81 today.")),
    tti(0, 3, 0xFF, 0, [10, 0, 7, 5], [10, 0, 10, 0], 1, 1, 0,
        teletextRows("Caf\xC2e \x80cr\xC1eme\x81 \xA43 or \xA32", "\x82\xC8Apfel\x83 und Stra\xFBe")),
    tti(0, 4, 0xFF, 0, [10, 0, 11, 0], [10, 0, 13, 0], 22, 2, 1, "Check the price list before air."),
    tti(0, 5, 0xFE, 0, [10, 0, 11, 0], [10, 0, 13, 0], 12, 3, 0, "PRICE LIST V2"),
    tti(0, 5, 0xFF, 0, [10, 0, 11, 0], [10, 0, 13, 0], 12, 3, 0, teletextRows("Sign: \xA9Open\xB9 \x24")),
    tti(0, 6, 0x00, 0, [10, 0, 14, 0], [10, 0, 20, 0], 16, 2, 0, substr($longText, 0, 112)),
    tti(0, 6, 0xFF, 0, [10, 0, 14, 0], [10, 0, 20, 0], 16, 2, 0, substr($longText, 112)),
    tti(0, 7, 0xFF, 0, [10, 0, 30, 24], [10, 0, 33, 0], 22, 0, 0, TELETEXT_ROW_START . "     Good night." . TELETEXT_ROW_END),
];
write("bakery_teletext_25fps.stl", gsi([
    "CPN" => "850", "DFC" => "STL25.01", "DSC" => "1", "CCT" => "00", "LC" => "09", "OPT" => "Bakery News",
    "OET" => "Morning bake", "TN" => "Jane Doe", "SLR" => "BAKERY-0001", "CD" => "260930", "RD" => "261001", "RN" => "02",
    "TNB" => "00009", "TNS" => "00006", "TNG" => "001", "MNC" => "40", "MNR" => "23", "TCS" => "1", "TCP" => "10000000",
    "TCF" => "10000100", "TND" => "1", "DSN" => "1", "CO" => "GBR", "PUB" => "Harbour Bakery Ltd",
    "UDA" => "Written for subtitle-toolbox tests.",
]), $bakery);


// Open subtitles, 30 fps, ISO 6937, a GSI title in code page 850, two subtitle groups and a cumulative set.
$harbour = [
    tti(0, 0, 0xFF, 1, [0, 0, 1, 15], [0, 0, 6, 0], 13, 2, 0, "Le vent tourne au nord."),
    tti(0, 1, 0xFF, 2, [0, 0, 2, 29], [0, 0, 6, 0], 0, 2, 0, "\x84La mer reste calme.\x85"),
    tti(0, 2, 0xFF, 3, [0, 0, 4, 10], [0, 0, 6, 0], 7, 1, 0, "\x82Les bateaux" . NEW_ROW . "restent au port.\x83"),
    tti(1, 3, 0xFF, 0, [0, 0, 7, 0], [0, 0, 9, 20], 13, 3, 0, "Bonne soir\xC2ee."),
    tti(1, 4, 0xFF, 0, [0, 1, 0, 0], [0, 1, 2, 29], 12, 2, 0, "Le port ferme \xC1a minuit." . NEW_ROW . "\x80Fin\x81"),
];
write("harbour_open_30fps.stl", gsi([
    "CPN" => "850", "DFC" => "STL30.01", "DSC" => "0", "CCT" => "00", "LC" => "0F", "OPT" => "M\x82t\x82o du port",
    "CD" => "261002", "RD" => "261002", "RN" => "00", "TNB" => "00005", "TNS" => "00005", "TNG" => "002",
    "MNC" => "38", "MNR" => "15", "TCS" => "1", "TCP" => "00000000", "TCF" => "00000115", "TND" => "1", "DSN" => "1",
    "CO" => "FRA",
]), $harbour);


// The train leaves at seven o'clock. / Next station: / North station.
write("train_cyrillic.stl", gsi([
    "CPN" => "850", "DFC" => "STL25.01", "DSC" => "1", "CCT" => "01", "LC" => "56", "OPT" => "Train", "CD" => "261002",
    "RD" => "261002", "RN" => "00", "TNB" => "00002", "TNS" => "00002", "TNG" => "001", "MNC" => "40", "MNR" => "23",
    "TCS" => "1", "TCP" => "00000000", "TCF" => "00000200", "TND" => "1", "DSN" => "1", "CO" => "   ",
]), [
    tti(0, 1, 0xFF, 0, [0, 0, 2, 0], [0, 0, 4, 0], 22, 2, 0,
        teletextRows("\xBF\xDE\xD5\xD7\xD4 \xDE\xE2\xDF\xE0\xD0\xD2\xDB\xEF\xD5\xE2\xE1\xEF \xD2 \xE1\xD5\xDC\xEC \xE7\xD0\xE1\xDE\xD2.")),
    tti(0, 2, 0xFF, 0, [0, 0, 5, 0], [0, 0, 8, 0], 20, 2, 0, teletextRows(
        "\xC1\xDB\xD5\xD4\xE3\xEE\xE9\xD0\xEF \xE1\xE2\xD0\xDD\xE6\xD8\xEF:",
        "\x02\xC1\xD5\xD2\xD5\xE0\xDD\xEB\xD9 \xD2\xDE\xDA\xD7\xD0\xDB."
    )),
]);


// It will rain tomorrow. / The sun rises at six. / The wind is weak.
write("weather_greek.stl", gsi([
    "CPN" => "850", "DFC" => "STL25.01", "DSC" => "1", "CCT" => "03", "LC" => "70", "OPT" => "Weather", "CD" => "261002",
    "RD" => "261002", "RN" => "00", "TNB" => "00003", "TNS" => "00003", "TNG" => "001", "MNC" => "40", "MNR" => "23",
    "TCS" => "1", "TCP" => "00000000", "TCF" => "00000100", "TND" => "1", "DSN" => "1", "CO" => "GRC",
]), [
    tti(0, 1, 0xFF, 0, [0, 0, 1, 0], [0, 0, 3, 0], 22, 2, 0, teletextRows("\xC1\xFD\xF1\xE9\xEF \xE8\xE1 \xE2\xF1\xDD\xEE\xE5\xE9.")),
    tti(0, 2, 0xFF, 0, [0, 0, 3, 10], [0, 0, 6, 0], 22, 2, 0,
        teletextRows("\xCF \xDE\xEB\xE9\xEF\xF2 \xE2\xE3\xE1\xDF\xED\xE5\xE9 \xF3\xF4\xE9\xF2 \xDD\xEE\xE9.")),
    tti(0, 3, 0xFF, 0, [0, 0, 6, 10], [0, 0, 9, 0], 22, 2, 0,
        teletextRows("\xCF \xDC\xED\xE5\xEC\xEF\xF2 \xE5\xDF\xED\xE1\xE9 \xE1\xF3\xE8\xE5\xED\xDE\xF2.")),
]);


// The market is open today. / Is the bread fresh? / Yes, from the oven.
write("market_arabic.stl", gsi([
    "CPN" => "850", "DFC" => "STL25.01", "DSC" => "1", "CCT" => "02", "LC" => "7E", "OPT" => "Market", "CD" => "261002",
    "RD" => "261002", "RN" => "00", "TNB" => "00002", "TNS" => "00002", "TNG" => "001", "MNC" => "40", "MNR" => "23",
    "TCS" => "1", "TCP" => "00000000", "TCF" => "00000100", "TND" => "1", "DSN" => "1", "CO" => "EGY",
]), [
    tti(0, 1, 0xFF, 0, [0, 0, 1, 0], [0, 0, 3, 0], 22, 3, 0, teletextRows("\xC7\xE4\xD3\xE8\xE2 \xE5\xE1\xCA\xE8\xCD \xC7\xE4\xEA\xE8\xE5.")),
    tti(0, 2, 0xFF, 0, [0, 0, 3, 10], [0, 0, 6, 0], 20, 3, 0,
        teletextRows("\xC7\xE4\xCE\xC8\xD2 \xD7\xC7\xD2\xCC\xBF", "\xE6\xD9\xE5\xAC \xE5\xE6 \xC7\xE4\xE1\xD1\xE6.")),
]);


// The library is open until eight. / The train leaves at seven. / Thank you and goodbye.
write("library_hebrew.stl", gsi([
    "CPN" => "850", "DFC" => "STL25.01", "DSC" => "1", "CCT" => "04", "LC" => "6C", "OPT" => "Library", "CD" => "261002",
    "RD" => "261002", "RN" => "00", "TNB" => "00003", "TNS" => "00003", "TNG" => "001", "MNC" => "40", "MNR" => "23",
    "TCS" => "1", "TCP" => "00000000", "TCF" => "00000100", "TND" => "1", "DSN" => "1", "CO" => "ISR",
]), [
    tti(0, 1, 0xFF, 0, [0, 0, 1, 0], [0, 0, 3, 0], 22, 3, 0,
        teletextRows("\xE4\xF1\xF4\xF8\xE9\xE9\xE4 \xF4\xFA\xE5\xE7\xE4 \xF2\xE3 \xF9\xEE\xE5\xF0\xE4.")),
    tti(0, 2, 0xFF, 0, [0, 0, 3, 10], [0, 0, 6, 0], 22, 3, 0, teletextRows("\xE4\xF8\xEB\xE1\xFA \xE9\xE5\xF6\xE0\xFA \xE1\xF9\xE1\xF2.")),
    tti(0, 3, 0xFF, 0, [0, 0, 6, 10], [0, 0, 9, 0], 22, 3, 0, teletextRows("\xFA\xE5\xE3\xE4 \xE5\xEC\xE4\xFA\xF8\xE0\xE5\xFA.")),
]);
