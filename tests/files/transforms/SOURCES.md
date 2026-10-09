# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `own_cea608_caps.vtt` | Written for this repository in the shape of WebVTT that a CEA-608 caption converter emits: all upper case, `>>` speaker changes, unescaped `&`, cue settings, `Kind` and `Language` headers, `NOTE` comments and a sound cue in brackets. | MIT |
| `own_cea608_caps_sentence.vtt` | Written for this repository. Expected `WebVttFormatter` output for `own_cea608_caps.vtt` after `changeCase("sentence")`. | MIT |
| `own_cea608_caps_cleaned.vtt` | Written for this repository. Expected `WebVttFormatter` output for `own_cea608_caps.vtt` after the bracket and dot replacements and `stripFormatting()`. | MIT |
| `own_cea608_run_on.vtt` | Written for this repository in the shape of `own_cea608_caps.vtt`. Sentences that run across cues, the English pronoun `I` with contractions, a gap of 2 s, and sentence ends before a closing quote, a closing bracket and `…`. | MIT |
| `own_cea608_run_on_sentence.vtt` | Written for this repository. Expected `WebVttFormatter` output for `own_cea608_run_on.vtt` after `changeCase("sentence")`. | MIT |
| `own_multilingual_caps.srt` | Written for this repository. SubRip with UTF-8 BOM and CR LF line endings. Upper case Greek, German and Turkish text, bold, italic and colour tags, and `<` and `&` in the text. | MIT |
