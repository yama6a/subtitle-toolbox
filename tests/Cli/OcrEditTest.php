<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use GlyphOcr\GlyphDatabase;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Cli\Edits\OcrEdit;
use SubtitleToolbox\Ocr\GlyphOcrEngine;

class OcrEditTest extends TestCase
{
    public function testTheGlyphDatabaseLoadsOncePerRun(): void
    {
        $edit    = OcrEdit::fromArguments(Arguments::parse(["--ocr", "--ocr-engine", "glyph"], OcrEdit::options()));
        $console = new Console(fopen("php://memory", "rb"), fopen("php://memory", "wb"), fopen("php://memory", "wb"));
        $engine  = new \ReflectionMethod($edit, "engine");

        // One engine per input, as for 2 inputs. Only a weak reference to the database of the first one stays here.
        $first = $engine->invoke($edit, $console);
        $this->assertInstanceOf(GlyphOcrEngine::class, $first);
        $database = \WeakReference::create($first->database());
        unset($first);
        gc_collect_cycles();
        $second = $engine->invoke($edit, $console);

        $this->assertNotNull($database->get());
        $this->assertSame($database->get(), $second->database());
    }


    public function testTheOcrDatabaseLoadsOnceInLoadSideFiles(): void
    {
        $path = sys_get_temp_dir() . "/ocr-edit-" . bin2hex(random_bytes(4)) . ".nocr";
        $edit = OcrEdit::fromArguments(Arguments::parse(["--ocr", "--ocr-database", $path], OcrEdit::options()));
        (new GlyphDatabase())->save($path);
        try {
            $edit->loadSideFiles();
        } finally {
            unlink($path);
        }
        $console = new Console(fopen("php://memory", "rb"), fopen("php://memory", "wb"), fopen("php://memory", "wb"));
        $engine  = new \ReflectionMethod($edit, "engine");

        $first  = $engine->invoke($edit, $console);
        $second = $engine->invoke($edit, $console);

        $this->assertInstanceOf(GlyphOcrEngine::class, $first);
        $this->assertSame($first->database(), $second->database());
    }
}
