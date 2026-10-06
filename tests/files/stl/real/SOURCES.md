# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `astisub_opn.stl` | https://github.com/asticode/go-astisub/blob/7671e72e9d47287d6f8ded80a004560b63a2ead5/testdata/example-opn-in.stl | go-astisub, MIT |
| `bakery_teletext_25fps.stl` | Written for this repository by `../generate-fixtures.php`. Teletext codes, ISO 6937 accents, extension blocks, a comment block, a user data block and a start of programme of 10:00:00:00 | MIT |
| `harbour_open_30fps.stl` | Written for this repository by `../generate-fixtures.php`. Open subtitles at 30 fps, a code page 850 title, two subtitle groups and a cumulative set | MIT |
| `library_hebrew.stl` | Written for this repository by `../generate-fixtures.php`. Character code table 04, ISO 8859-8 | MIT |
| `market_arabic.stl` | Written for this repository by `../generate-fixtures.php`. Character code table 02, ISO 8859-6 | MIT |
| `shop_comments_out_of_order.stl` | Written for this repository by `../generate-fixtures.php`. Subtitles out of time order, and a comment block before a subtitle that starts earlier than the subtitle before it | MIT |
| `train_cyrillic.stl` | Written for this repository by `../generate-fixtures.php`. Character code table 01, ISO 8859-5 | MIT |
| `weather_greek.stl` | Written for this repository by `../generate-fixtures.php`. Character code table 03, ISO 8859-7 | MIT |

The go-astisub files `example-in.stl` and `example-in-nonzero-offset.stl` hold film dialogue, so this repository does not copy them.

Regenerate the self-written files with `php tests/files/stl/generate-fixtures.php`. The script writes every byte itself and does not use the library.
