<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class SubRipStreamWriter implements CueStreamWriter
{
    /** @var resource|null */
    private $handle;

    private bool $ownsHandle;

    private SubRipFormatter $formatter;

    private int $cueIndex = 0;


    /**
     * @param resource|string $stream a stream resource, or a file path that the writer opens and closes
     */
    public function __construct($stream, private readonly WriteOptions $options = new WriteOptions())
    {
        $this->formatter  = new SubRipFormatter();
        $prefix           = $this->formatter->format(new Subtitle(), $options);
        $this->ownsHandle = !is_resource($stream);
        $this->handle     = Streams::open($stream, "wb");
        Streams::write($this->handle, $prefix);
    }


    public function write(SubtitleCue $cue): void
    {
        Streams::write($this->handle, $this->formatter->formatCueBlock($cue, $this->cueIndex++, $this->options));
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
