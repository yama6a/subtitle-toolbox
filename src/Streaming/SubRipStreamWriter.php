<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class SubRipStreamWriter implements CueStreamWriter
{
    private readonly StreamHandle $handle;

    private SubRipFormatter $formatter;

    private int $cueIndex = 0;

    private readonly WriteOptions $options;


    /**
     * @param resource|string $stream a stream resource, or a file path that the writer opens and closes
     */
    public function __construct($stream, ?WriteOptions $options = null)
    {
        $options ??= new WriteOptions();
        $this->options = $options;
        $this->formatter  = new SubRipFormatter();
        $prefix           = $this->formatter->format(new Subtitle(), $options);
        $this->handle     = new StreamHandle($stream);
        $this->handle->write($prefix);
    }


    public function write(SubtitleCue $cue): void
    {
        $this->handle->write($this->formatter->formatCueBlock($cue, $this->cueIndex++, $this->options));
    }


    public function close(): void
    {
        $this->handle->close();
    }
}
