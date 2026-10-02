# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `own_cea608_caps.vtt` | Written for this repository in the shape of WebVTT that a CEA-608 caption converter emits: all upper case, `>>` speaker changes, unescaped `&`, cue settings, `Kind` and `Language` headers, `NOTE` comments and a sound cue in brackets. | MIT |
| `own_cea608_caps_sentence.vtt` | Written for this repository. Expected `WebVttFormatter` output for `own_cea608_caps.vtt` after `changeCase("sentence")`. | MIT |
| `own_cea608_caps_cleaned.vtt` | Written for this repository. Expected `WebVttFormatter` output for `own_cea608_caps.vtt` after the bracket and dot replacements and `stripFormatting()`. | MIT |
| `own_multilingual_caps.srt` | Written for this repository. SubRip with UTF-8 BOM and CR LF line endings. Upper case Greek, German and Turkish text, bold, italic and colour tags, and `<` and `&` in the text. | MIT |
