<?php

declare(strict_types=1);

namespace SubtitleToolbox\Tests\Support;

/**
 * A stream wrapper for counting:// paths. It reads the local file after the scheme. It counts the opens and the bytes that the reads return.
 */
final class CountingFileStream
{
    public const SCHEME = "counting";

    public static int $opens = 0;

    public static int $bytesRead = 0;

    /** @var resource|null */
    public $context;

    /** @var resource */
    private $file;


    public static function register(): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        self::reset();
    }


    public static function reset(): void
    {
        self::$opens     = 0;
        self::$bytesRead = 0;
    }


    public static function unregister(): void
    {
        stream_wrapper_unregister(self::SCHEME);
    }


    public function stream_open(string $path, string $mode): bool
    {
        $file = fopen(self::localPath($path), $mode);
        if ($file === false) {
            return false;
        }
        $this->file = $file;
        self::$opens++;

        return true;
    }


    public function stream_read(int $count): string|false
    {
        $bytes            = fread($this->file, $count);
        self::$bytesRead += $bytes === false ? 0 : strlen($bytes);

        return $bytes;
    }


    public function stream_eof(): bool
    {
        return feof($this->file);
    }


    public function stream_close(): void
    {
        fclose($this->file);
    }


    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->file);
    }


    public function stream_seek(int $offset, int $whence): bool
    {
        return fseek($this->file, $offset, $whence) === 0;
    }


    public function stream_tell(): int
    {
        return (int) ftell($this->file);
    }


    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return @stat(self::localPath($path));
    }


    private static function localPath(string $path): string
    {
        return substr($path, strlen(self::SCHEME . "://"));
    }
}
