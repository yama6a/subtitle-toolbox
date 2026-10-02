# Sources

All files here are written for this repository. They hold no third-party text.

`generate.php` draws the cues of `OCR_CUES` with the PGS fixture writer of `tests/files/pgs/generator/` and the font of `tests/files/ocr/`. It then reads them with `GlyphOcrEngine` and the Latin database of php-glyph-ocr. Run it from the repository root to rebuild the `.sup` and `.ocr.srt` files:

```sh
php tests/files/fixing/generate.php
```

| File | Source | License |
|:--- |:--- |:--- |
| `ocr-en.sup`, `ocr-de.sup`, `ocr-fr.sup`, `ocr-es.sup` | Written for this repository by `generate.php`. 1920x1080, white Liberation Sans at 52 px, 2 to 6 cues in English, German, French and Spanish with many capital I | MIT |
| `ocr-en.ocr.srt`, `ocr-de.ocr.srt`, `ocr-fr.ocr.srt`, `ocr-es.ocr.srt` | The text that `GlyphOcrEngine` reads from the `.sup` file of the same name, OCR errors included | MIT |
| `web-errors.srt` | Written for this repository in the shape of a downloaded SubRip file: UTF-8 BOM, CR LF, `<i>`, `<b>` and `<font color>` tags. 12 cues with spacing, punctuation, dash and tag errors | MIT |
| `user_OCRFixReplaceList.xml` | Written for this repository in the shape of a Subtitle Edit user replace list, with entries for the OCR errors of `../vobsub/text-pal.ocr.srt` | MIT |
| `web-errors.fixed.srt`, `ocr-*.fixed.srt` | `CommonErrorFixer` output for the file of the same name, with the default options and its language | MIT |
| `text_1080p.fixed.srt` | `CommonErrorFixer` output for `../pgs/text_1080p.ocr.srt`, language `en` | MIT |
| `text-pal.fixed.srt` | `CommonErrorFixer` output for `../vobsub/text-pal.ocr.srt`, language `en`, with `user_OCRFixReplaceList.xml` | MIT |
