# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `own_station.srt` | Written for this repository in the shape of a SubRip file: CR LF, sentences over two cues, italics over two lines and over two cues, a music cue and a number cue. | MIT |
| `own_entities.srt` | Written for this repository in the shape of a SubRip file: text that holds the entities `&lt;`, `&gt;` and `&amp;`. | MIT |
| `deepl_de.json` | Written for this repository in the shape of a DeepL `/v2/translate` response to the sentences of `own_station.srt`. | MIT |
| `deepl_error.json` | Written for this repository in the shape of a DeepL HTTP 400 error body. | MIT |
| `google_fr.json` | Written for this repository in the shape of a Cloud Translation v2 `translate` response to the sentences of `own_station.srt`. | MIT |
| `google_error.json` | Written for this repository in the shape of a Cloud Translation v2 HTTP 400 error body. | MIT |
| `fake-server.php` | Written for this repository. A `php -S` router script that plays DeepL and Google in the tests. | MIT |
