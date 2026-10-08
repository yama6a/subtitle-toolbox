<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class WebVttStreamWriter implements CueStreamWriter
{
    private readonly StreamHandle $handle;

    private WebVttFormatter $formatter;

    private string $lineEnding;

    private bool $hasBlocks;

    private int $cueIndex = 0;

    private readonly WriteOptions $options;


    /**
     * @param resource|string     $stream a stream resource, or a file path that the writer opens and closes
     * @param array<string, mixed> $header the header, STYLE and REGION blocks, as WebVttStreamReader::getHeader() returns them
     */
    public function __construct($stream, ?WriteOptions $options = null, array $header = [])
    {
        $options ??= new WriteOptions();
        $this->options = $options;
        $this->formatter  = new WebVttFormatter();
        $headerOnly       = (new Subtitle())->setFormatData(WebVttParser::FORMAT_DATA_KEY, $header);
        $prefix           = $this->formatter->format($headerOnly, $options);
        $this->lineEnding = $options->lineEnding->value;
        $this->hasBlocks  = ($header["styles"] ?? []) !== [] || ($header["regions"] ?? []) !== [];
        $this->handle     = new StreamHandle($stream);
        $this->handle->write($prefix);
    }


    public function write(SubtitleCue $cue): void
    {
        $block = $this->formatter->formatCueBlock($cue, $this->cueIndex++, $this->options) . $this->lineEnding;
        $this->handle->write($this->hasBlocks ? $this->lineEnding . $block : $block);
        $this->hasBlocks = true;
    }


    public function close(): void
    {
        $this->handle->close();
    }
}
