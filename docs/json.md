# JSON and arrays

A web app stores the cues in a database and sends them to the browser as JSON. `toArray()`, `fromArray()`, `JsonFormatter` and `JsonParser` convert a subtitle without loss.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\JsonFormatter;
use SubtitleToolbox\Subtitle;

$array = $subtitle->toArray();                     // toArray(false) leaves out the format data
$copy  = Subtitle::fromArray($array);              // equal to $subtitle
$json  = $subtitle->toString(Format::Json, [JsonFormatter::OPTION_PRETTY_PRINT => true]);
$copy  = Subtitle::fromString($json, Format::Json);
```

`JsonFormatter` writes this shape. `toArray()` returns the same shape as a PHP array, with binary strings as they are.

```json
{
    "version": 1,
    "metadata": {"title": "Big Buck Bunny", "language": "en"},
    "comments": [{"text": "Translated by Jane Doe", "beforeCueIndex": 0}],
    "formatData": {"ass": {"scriptInfo": {"PlayResX": "1920"}}},
    "cues": [
        {"start": 1.5, "end": 4.0, "lines": ["Hello", "<i>world</i>"], "identifier": "intro", "alignment": 8, "forced": true, "formatData": {}},
        {"start": 5.0, "end": 6.5, "lines": [], "identifier": null, "alignment": null,
         "formatData": {"image": {"png": {"base64": "iVBORw0KGgo..."}, "x": 640, "y": 940, "width": 2, "height": 1,
                                  "screenWidth": 1920, "screenHeight": 1080, "forced": false}}}
    ]
}
```

| Field | Type | Required | Content |
|:--- |:--- |:--- |:--- |
| `version` | integer | yes | 1. A later version of the shape gets a new number. `fromArray()` rejects all other numbers |
| `metadata` | object of strings | no | the keys of `getAllMetadata()` |
| `comments` | list of objects | no | `text` and `beforeCueIndex`, as `getComments()` returns them |
| `formatData` | object of objects | no | the format data of the subtitle by format key |
| `cues` | list of objects | yes | the cues in this order. `fromArray()` does not sort them |
| `cues[].start`, `cues[].end` | number | yes | seconds, rounded to milliseconds |
| `cues[].lines` | list of strings | yes | the lines with core markup and escaped `&lt;`, `&gt;` and `&amp;` |
| `cues[].identifier` | string or null | no | the cue identifier |
| `cues[].alignment` | integer or null | no | 1 to 9 in numeric keypad layout |
| `cues[].forced` | boolean | no | written only for a forced cue |
| `cues[].formatData` | object of objects | no | the format data of the cue by format key |

- **Binary data**: `JsonFormatter` writes each format data string that is not valid UTF-8 as `{"base64": "..."}`. The PNG of an image cue is such a string. `JsonParser` decodes every object in the format data that has `base64` as its only key.
- **Errors**: `JsonParser` and `fromArray()` throw `ParsingException` with the path of the bad field, for example `The field cues[3].start must be a number.`
- **Text**: cue lines and metadata must be UTF-8. Otherwise `JsonFormatter` throws `JsonException`. Parse a file in another encoding with its [source encoding](encodings.md).
- **Options**: `OPTION_PRETTY_PRINT` indents with 4 spaces and ends with a newline. `OPTION_WITH_FORMAT_DATA => false` leaves out the format data. The options `lineEnding` and `bom` work as in the other formatters.
- **Detection**: an object with a numeric `version` key and a `cues` list detects as `Format::Json`. Detection fails when more than about 70,000 cues come before the `version` key. Then pass `Format::Json`. `JsonFormatter` writes `version` first.
