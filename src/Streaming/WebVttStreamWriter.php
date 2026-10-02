<?php

namespace SubtitleToolbox\Streaming;

use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class WebVttStreamWriter implements CueStreamWriter
{
    /** @var resource|null */
    private $handle;

    private bool $ownsHandle;

    private WebVttFormatter $formatter;

    private string $lineEnding;

    private bool $hasBlocks;

    private int $cueIndex = 0;


    /**
     * @param resource|string $stream  a stream resource, or a file path that the writer opens and closes
     * @param array           $header  the header, STYLE and REGION blocks, as WebVttStreamReader::getHeader() returns them
     * @param array           $options the options of WebVttFormatter::format()
     */
    public function __construct($stream, array $header = [], private readonly array $options = [])
    {
        $this->formatter  = new WebVttFormatter();
        $headerOnly       = (new Subtitle())->setFormatData(WebVttParser::FORMAT, $header);
        $prefix           = $this->formatter->format($headerOnly, $options);
        $this->lineEnding = $options[SubtitleFormatter::OPTION_LINE_ENDING] ?? StringHelpers::UNIX_LINE_ENDING;
        $this->hasBlocks  = ($header["styles"] ?? []) !== [] || ($header["regions"] ?? []) !== [];
        $this->ownsHandle = !is_resource($stream);
        $this->handle     = Streams::open($stream, "wb");
        Streams::write($this->handle, $prefix);
    }


    public function write(SubtitleCue $cue): void
    {
        $block = $this->formatter->formatCueBlock($cue, $this->cueIndex++, $this->options) . $this->lineEnding;
        Streams::write($this->handle, $this->hasBlocks ? $this->lineEnding . $block : $block);
        $this->hasBlocks = true;
    }


    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }

        fflush($this->handle);
        if ($this->ownsHandle) {
            fclose($this->handle);
        }
        $this->handle = null;
    }
}
