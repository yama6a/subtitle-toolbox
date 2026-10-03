# Image subtitles and OCR

Blu-ray discs store subtitles as PGS bitmaps, DVDs as VobSub bitmaps. The library reads both as image cues. OCR (optical character recognition) turns the bitmaps into text, so you can write a text format.

## Image cues
An **image cue** is a cue with a PNG image in the format data key `image`. It has no text lines until an OCR engine reads it.

```php
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\GlyphOcrEngine;

$image = CueImage::fromCue($cue);                    // $image->png, x, y, width, height, screenWidth, screenHeight, forced
file_put_contents('cue.png', $image->png);

$subtitle->recognizeText(new GlyphOcrEngine());      // sets the lines of each image cue without text
$subtitle->format(SubRipFormatter::class);

$subtitle->format(SubRipFormatter::class, [
    SubtitleFormatter::OPTION_SKIP_IMAGE_CUES => true,    // drops image cues without text
]);
```

- **Text formatters**: `format()` throws `ImageCueWithoutTextException` for an image cue without text. So a file without OCR fails at once, and does not become a valid file with missing cues.
- **After OCR**: the cue keeps its image, so `PgsFormatter` can still write it.
- **Language**: `recognizeText()` passes the language code to the engine as it is. Use a code that the engine knows, for example `eng` for Tesseract.
- **Confidence**: `(new OcrRunner($engine))->run($subtitle, 'eng')` does the same as `recognizeText()` and returns the `OcrResult` of each cue by cue index.
- **Forced flag**: `CueImage::toCue()` sets the forced flag of the cue from the `forced` field of the image. OCR keeps the flag.
- **PNG**: `PngEncoder::encode($width, $height, $pixels)` makes a PNG from a list of `0xRRGGBBAA` integers. It needs no ext-gd. It compresses with ext-zlib when it is loaded, and else writes larger, uncompressed PNG files. `PngDecoder::decode($png)` returns the width, the height and the pixels of a PNG without interlacing. It needs ext-zlib.
- **Text errors**: [fix common OCR errors](text.md#fixing-common-errors) such as `lt's` for `It's`.

## PGS
Blu-ray discs and many MKV files store subtitles as PGS bitmaps in `.sup` files.

```php
use SubtitleToolbox\Formatters\PgsFormatter;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::parse(file_get_contents('movie.sup'));                     // detects PGS
$subtitle = (new PgsParser(3.0))->parse(file_get_contents('movie.sup'));         // the last cue lasts 3 s, not 5 s
$subtitle->shift(-1.5)->convertFrameRate(25, 23.976);
file_put_contents('movie.synced.sup', $subtitle->format(PgsFormatter::class));
```

- **Cues**: each display set that shows objects gives one cue. It ends at the next display set. A display set that repeats the same image does not start a new cue. A last cue that no later display set ends lasts 5 s, or the constructor argument in seconds.
- **Image**: one PNG covers all objects of the display set on a transparent background. The parser applies cropping, windows and palette updates.
- **Forced**: `forced` in the image data is true when at least one object of the display set has the forced flag.
- **Alignment**: an image whose center is in the top third of the screen gets alignment 8.
- **Errors**: the parser skips segments of unknown types. It throws `ParsingException` for a segment without the `PG` bytes, a cut-off segment, and a bitmap with too few pixels.
- **Formatter**: `PgsFormatter` writes image cues back to a `.sup` file. So you can retime, cut or filter a PGS file without OCR. It also converts VobSub to PGS. It does not render text, and throws `InvalidArgumentException` for a cue without an image.
- **Overlaps**: the formatter writes the cues in start order. A cue that starts before the previous cue ends replaces it on screen.
- **Colours**: the formatter reduces an image with more than 255 colours. A colour channel can change by 1.
- **Round trip**: a PGS file that `PgsParser` reads and `PgsFormatter` writes gives the same pixels, positions and times to 1 ms. Two cues with the same image, where the second starts at the end of the first, come back as one cue.
- **Speed**: parsing or writing a 1,500-cue file takes about 20 s on PHP 8.5. The PNG compression takes most of this time.

## VobSub
VobSub is the subtitle format of DVD rips. It is a pair of files. The `.idx` text file holds the palette, the screen size, the tracks and their timestamps. The `.sub` file holds the bitmaps.

```php
use SubtitleToolbox\Parsers\VobSubParser;

$idx      = file_get_contents('movie.idx');
$subtitle = (new VobSubParser($idx))->parse(file_get_contents('movie.sub'));         // first track
$subtitle = (new VobSubParser($idx, 'de'))->parse(file_get_contents('movie.sub'));   // first track with "id: de"
$subtitle = (new VobSubParser($idx, 1))->parse(file_get_contents('movie.sub'));      // track with "index: 1"

$subtitle->getMetadata(Subtitle::METADATA_LANGUAGE);   // "de", from the id line
```

- **Parse**: call the parser directly. `Subtitle::parse()` creates the parser without arguments, so it cannot pass the `.idx` content. For the same reason, format detection does not know VobSub. The command line tool takes the `.idx` file as input and reads the `.sub` file next to it.
- **Cues**: the image has the size and the position of the display area, on a screen of the `.idx` size. A subpicture with the forced start command sets `forced`.
- **Times**: a cue starts at its `timestamp`, plus the `delay` lines of its track. It ends at the stop command of the subpicture. A subpicture without a stop command ends at the next one, at most 5 s later.
- **Colours**: the `.idx` palette and a `custom colors: ON` line apply.
- **Limits**: the parser reads one image per subpicture. Colour and contrast changes after the start command do not apply. The parser ignores the `org`, `scale`, `align`, `fadein/out` and `time offset` player settings.
- **No formatter**: convert VobSub to PGS with `PgsFormatter`, or to text after OCR.

## Built-in OCR
`GlyphOcrEngine` reads the bitmaps of PGS and VobSub cues in pure PHP. It uses the optional package [yama6a/php-glyph-ocr](https://github.com/yama6a/php-glyph-ocr), a port of the nOCR engine of Subtitle Edit.

```sh
composer require yama6a/php-glyph-ocr:^0.3
```

```php
$subtitle = Subtitle::parse(file_get_contents('movie.sup'));            // PGS, image cues
$subtitle->recognizeText(new GlyphOcrEngine());                        // subtitle fonts database by default
file_put_contents('movie.srt', $subtitle->format(SubRipFormatter::class));
```

- **Package**: without php-glyph-ocr, `new GlyphOcrEngine()` throws `InvalidArgumentException` with the `composer require` command.
- **Database**: the first argument is a `GlyphOcr\GlyphDatabase`. The default is `GlyphDatabase::subtitleFonts()`. It holds glyphs of DejaVu Sans, Liberation Sans and Noto Sans, upright and italic, and then the Latin database of Subtitle Edit for other fonts. Liberation Sans has the metrics of Arial. The database takes about 76 MB of memory, so engines that exist at the same time share one copy.
- **Subtitle Edit output**: `new GlyphOcrEngine(GlyphDatabase::latin(), ['lineContext' => false])` reads the text as the nOCR engine of Subtitle Edit does.
- **Options**: the second argument holds named arguments of `GlyphOcr\Recognizer`, for example `['italicSlant' => 0.2]`. An unknown name or an invalid value throws `InvalidArgumentException`.
- **One engine per stream**: the engine learns the glyph heights from the cues it reads. So use a new engine for each subtitle stream.
- **Italic**: a word becomes italic when most of its characters match italic glyphs.
- **Language**: the engine ignores the language argument. The database sets the characters it knows.
- **Confidence**: the `OcrResult` confidence is the mean confidence of the glyphs of the cue. A glyph that matches nothing reads as `*` with confidence 0.
- **I and l**: most sans-serif fonts draw capital I and lower case l as the same bar. The engine compares each bar with the capitals and the ascenders of its line, so it reads both letters correctly in the test files. The recognizer option `lineContext` controls this and is on by default. Fix remaining errors with [`CommonErrorFixer`](text.md#fixing-common-errors).
- **Limits**: the text must have one colour on a transparent or dark background.
- **Accuracy**: the default database reads the 1080p Blu-ray test file in Liberation Sans with 100% correct characters, and small DVD text with 98%. Other fonts give more errors. Training a database for the font of your file fixes most of them.
- **Speed and memory**: OCR of a 1,500-cue 1080p PGS file takes about 2 minutes on one core with PHP 8.5. It needs up to 170 MB of memory. Raise `memory_limit` above the default 128 MB for a long file.

### Training a database
Train a glyph from a sample that a person confirmed. Then save the database and pass it to the engine:

```php
use GlyphOcr\GlyphDatabase;
use GlyphOcr\Image;
use GlyphOcr\Recognizer;
use GlyphOcr\Trainer;

$database   = GlyphDatabase::subtitleFonts();
$recognizer = new Recognizer($database);
$trainer    = new Trainer();
foreach ($subtitle->getCues() as $cue) {
    $result = $recognizer->recognize(Image::fromPng(CueImage::fromCue($cue)->png));
    foreach ($result->unknownChars() as $char) {
        $database->add($trainer->train($char->sample, askAPerson($char->sample->toAscii())));
    }
}
$database->save('my-font.nocr');

$subtitle->recognizeText(new GlyphOcrEngine(GlyphDatabase::fromFile('my-font.nocr')));
```

- `askAPerson()` is your own code. It shows the glyph and returns the text that a person types.
- A new glyph wins over older glyphs that match equally well. Train a misread character the same way, from `$result->lines[$line]->chars[$index]->sample`.
- The [php-glyph-ocr README](https://github.com/yama6a/php-glyph-ocr#databases-and-training) describes the `.nocr` files, `Recognizer::split()` and `GlyphSample::merge()`.
- The command line tool takes a trained database with `--ocr-database`, see [cli.md](cli.md#ocr).

## Other OCR engines
An engine is a class that implements `OcrEngine`. This example calls the `tesseract` command:

```php
use SubtitleToolbox\Ocr\OcrEngine;
use SubtitleToolbox\Ocr\OcrResult;

final class TesseractEngine implements OcrEngine
{
    public function recognize(CueImage $image, ?string $language): OcrResult
    {
        $file = tempnam(sys_get_temp_dir(), 'cue');
        file_put_contents($file, $image->png);
        $text = shell_exec('tesseract ' . escapeshellarg($file) . ' - -l ' . escapeshellarg($language ?? 'eng'));
        unlink($file);

        return new OcrResult(explode("\n", trim((string)$text)));
    }
}

$subtitle->recognizeText(new TesseractEngine(), 'eng');
```

- **Lines**: the engine returns plain text or core markup, for example `<i>` for italic text. Empty lines are dropped.
- **Confidence**: pass a value from 0 to 1 as the second argument of `OcrResult`, or leave it null.
