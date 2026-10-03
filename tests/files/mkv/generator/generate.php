<?php

declare(strict_types=1);

// Writes the MKV fixtures to tests/files/mkv/. Run: php tests/files/mkv/generator/generate.php

namespace SubtitleToolbox\Container\Matroska;

require_once __DIR__ . "/MkvFixtures.php";

foreach (MkvFixtures::FILES as $file => $method) {
    file_put_contents(dirname(__DIR__) . "/$file", MkvFixtures::$method());
    echo "Wrote $file\n";
}
