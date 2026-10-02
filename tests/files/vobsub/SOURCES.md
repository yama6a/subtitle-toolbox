# Sources

Real VobSub files hold the bitmaps of film subtitles, which no permissive license covers. So `generate.php` in this folder writes every file here. Run it from the repository root to rebuild them:

```sh
php tests/files/vobsub/generate.php
```

The script has its own run-length encoder and MPEG-2 packer, separate from `VobSubParser`. It packs each subpicture unit into 2048-byte packs with a PTS in the first PES packet and padding packets, as DVD rips have. The `.idx` comment and setting lines follow the shape of the files that VSFilter writes (`CVobSubFile::WriteIdx` in [MPC-HC](https://github.com/clsid2/mpc-hc/blob/develop/src/Subtitles/VobSubFile.cpp)).

| File | Source | License |
|:--- |:--- |:--- |
| `two-tracks-pal.idx`, `two-tracks-pal.sub` | Written for this repository by `generate.php`. 720x576, CR LF, tracks `en` (index 0, 5 units) and `de` (index 1, 2 units, a `delay` line). One forced unit, one unit with a start delay, two units without a stop command, one unit over 6 packs, one time above 1 hour | MIT |
| `custom-colors-ntsc.idx`, `custom-colors-ntsc.sub` | Written for this repository by `generate.php`. 720x480, LF, `custom colors: ON`, tracks `ja` (index 0) and `fr` (index 2, sub-stream 0x22), one forced unit | MIT |
| `text-pal.idx`, `text-pal.sub` | Written for this repository by `generate.php`. 720x576, CR LF, track `en`, 6 units of 1 or 2 lines in Liberation Sans, 24 to 30 px. 4 colors: fill, fill edge, black outline and background. `TEXT_CUES` in `generate.php` holds the text. See [../ocr/SOURCES.md](../ocr/SOURCES.md) for the font and the renderer | MIT |
