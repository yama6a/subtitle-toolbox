<?php

// Writes bad_time_code.stl byte by byte, without the library code.
// Run it with: php tests/files/lenient/generate-stl.php

function gsi(): string
{
    $fields = [
        "850", "STL25.01", "0", "00", "09", str_pad("Bakery", 32), str_repeat(" ", 32 * 5), str_repeat(" ", 16),
        "261002", "261002", "01", "00005", "00004", "001", "40", "23", "1", "00000000", "00000100", "1", "1", "GBR",
    ];

    return str_pad(implode("", $fields), 1024);
}


function tti(int $number, array $in, array $out, string $text): string
{
    return chr(0) . pack("v", $number) . chr(0xFF) . chr(0) .
           implode("", array_map("chr", $in)) . implode("", array_map("chr", $out)) .
           chr(20) . chr(2) . chr(0) . str_pad($text, 112, "\x8F");
}


$blocks = [
    tti(1, [0, 0, 1, 0], [0, 0, 3, 0], "The bread is fresh."),
    tti(2, [0, 0, 4, 0], [0, 0, 6, 30], "The cakes are sold out."),
    tti(3, [0, 0, 7, 0], [0, 0, 9, 0], "We close at five."),
    substr(tti(4, [0, 0, 10, 0], [0, 0, 12, 0], "See you tomorrow."), 0, 60),
];
file_put_contents(__DIR__ . "/bad_time_code.stl", gsi() . implode("", $blocks));
