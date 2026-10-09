<?php

declare(strict_types=1);

// Writes the MP4 fixtures to tests/files/mp4/. Run: php tests/files/mp4/generator/generate.php

namespace SubtitleToolbox\Container\Mp4;

require_once __DIR__ . "/Mp4Fixtures.php";

foreach (Mp4Fixtures::FILES as $file => $method) {
    file_put_contents(dirname(__DIR__) . "/$file", Mp4Fixtures::$method());
    echo "Wrote $file\n";
}
