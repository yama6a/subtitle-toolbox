# Sources

Real `.sup` files hold bitmaps of film dialogue, so all files here are written for this repository. The `shapes` files show simple shapes. `text_1080p.sup` shows text that is written for this repository.

`generator/PgsFixtures.php` builds the files with `generator/PgsFixtureWriter.php`, which writes PGS segments and their run-length encoding. Run `php tests/files/pgs/generator/generate.php` to write them again. `PgsParserTest` checks that the files match the generator output.

| File | Source | License |
|:--- |:--- |:--- |
| `shapes_1080p.sup` | Written for this repository. 1920x1080, six cues: one object, two objects in two windows, a forced object split over several object segments, a palette update, a cropped object with an acquisition point and a segment of unknown type, and a last cue without a clearing display set | MIT |
| `shapes_576p.sup` | Written for this repository. 720x576, two cues, for the BT.601 colour matrix | MIT |
| `text_1080p.sup` | Written for this repository. 1920x1080, 12 cues of 1 or 2 lines in Liberation Sans, 44 to 60 px, with a black outline and 16 levels of anti-aliasing. Yellow and italic text, and one cue at the top. `TEXT_CUES` in `generator/PgsFixtures.php` holds the text. See [../ocr/SOURCES.md](../ocr/SOURCES.md) for the font and the renderer | MIT |
| `text_1080p.ocr.srt` | The text that `GlyphOcrEngine` reads from `text_1080p.sup` with the Latin database of php-glyph-ocr, OCR errors included | MIT |
