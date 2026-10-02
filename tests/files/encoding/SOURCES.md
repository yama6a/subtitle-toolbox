# Sources

All files are written for this repository. Each one started as a UTF-8 file with LF line endings. `sed 's/$/\r/'` added CR LF line endings, and `iconv -f UTF-8 -t <encoding>` wrote the encoding.

| File | Source | License |
|:--- |:--- |:--- |
| `french-windows-1252.srt` | Written for this repository. Windows-1252, CR LF, no BOM. Holds `é`, `€` and `œ`, which use bytes 0x80 to 0x9F | MIT |
| `russian-windows-1251.srt` | Written for this repository. Windows-1251, CR LF, no BOM | MIT |
| `japanese-shift_jis.srt` | Written for this repository. Shift_JIS, CR LF, no BOM. `ソ` and `表` have 0x5C as their second byte | MIT |
| `korean-cp949.smi` | Written for this repository in the shape of Korean SAMI files: a `KRCC` class and `<P>` without end tags. CP949, CR LF, no BOM. `똠` exists in CP949 but not in EUC-KR | MIT |
| `notepad-utf-16le.vtt` | Written for this repository in the shape that Windows Notepad saves as "UTF-16 LE": the BOM `FF FE` and CR LF | MIT |
