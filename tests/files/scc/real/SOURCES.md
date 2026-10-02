# Sources

[`../generate.php`](../generate.php) writes every file here. Run `php tests/files/scc/generate.php` after a change to it. The text is written for this repository.

| File | Source | License |
|:--- |:--- |:--- |
| `popon_broadcast_df.scc` | Written for this repository in the shape of the pop-on example in http://www.theneitherworld.com/mcpoodle/SCC_TOOLS/DOCS/SCC_FORMAT.HTML: EDM and two `8080` words before each EOC, drop-frame time codes across 00:59:00 and 01:00:00, PAC styles, mid-row codes, special and extended characters, captions on rows 1 and 2 | MIT |
| `rollup_news_ndf.scc` | Written for this repository in the shape of a live roll-up file: RU3, CR and a PAC on each line, words that continue on the next line, a change to RU2, non-drop time codes, CR LF line endings | MIT |
| `painton_corrections.scc` | Written for this repository in the shape of a paint-on file: RDC, a backspace, a delete to the end of the row, tab offsets, a space after the time code | MIT |
| `interleaved_pac_tab.scc` | Written for this repository in the shape of the file in https://github.com/pbs/pycaption/issues/194: PAC, tab offset, PAC, tab offset | MIT |
| `out_of_order_lines.scc` | Written for this repository in the shape of the output in https://github.com/pbs/pycaption/issues/352: the line of a short caption comes first in the file, but the line of a long caption has an earlier time code | MIT |
| `raw_data_row.scc` | Written for this repository in the shape of the file in https://github.com/SubtitleEdit/subtitleedit/issues/11341: a row of random byte pairs without a PAC between two valid captions | MIT |
