# Sources

Real `.sup` files hold bitmaps of film dialogue, so all files here are written for this repository. They show simple shapes and no text.

`generator/PgsFixtures.php` builds the files with `generator/PgsFixtureWriter.php`, which writes PGS segments and their run-length encoding. Run `php tests/files/pgs/generator/generate.php` to write them again. `PgsParserTest` checks that the files match the generator output.

| File | Source | License |
|:--- |:--- |:--- |
| `shapes_1080p.sup` | Written for this repository. 1920x1080, six cues: one object, two objects in two windows, a forced object split over several object segments, a palette update, a cropped object with an acquisition point and a segment of unknown type, and a last cue without a clearing display set | MIT |
| `shapes_576p.sup` | Written for this repository. 720x576, two cues, for the BT.601 colour matrix | MIT |
