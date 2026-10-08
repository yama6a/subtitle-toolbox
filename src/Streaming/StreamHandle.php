<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

/**
 * The output stream of a stream writer. It closes only a stream that it opened from a file path.
 *
 * @internal
 */
final class StreamHandle
{
    /** @var resource|null */
    private $handle;

    private readonly bool $ownsHandle;


    /**
     * @param resource|string $stream a stream resource, or a file path that this class opens and closes
     */
    public function __construct($stream)
    {
        $this->ownsHandle = !is_resource($stream);
        $this->handle     = Streams::open($stream, "wb");
    }


    public function write(string $text): void
    {
        Streams::write($this->handle, $text);
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
