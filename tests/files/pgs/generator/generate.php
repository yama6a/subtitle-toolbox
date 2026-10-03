<?php

declare(strict_types=1);

// Writes the PGS fixtures to tests/files/pgs/. Run: php tests/files/pgs/generator/generate.php

namespace SubtitleToolbox\Parsers;

require_once __DIR__ . "/PgsFixtures.php";

foreach (PgsFixtures::FILES as $file => $method) {
    file_put_contents(dirname(__DIR__) . "/$file", PgsFixtures::$method());
    echo "Wrote $file\n";
}
