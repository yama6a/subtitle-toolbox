<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

/**
 * Creates the files of one run with exclusive create, so it never overwrites a file. When a create fails, it removes
 * every file and directory that it created in the run.
 *
 * @internal
 */
final class OutputFiles
{
    /** @var (\Closure(string): void)|null a test hook that runs before each create, with the path of the file */
    public static ?\Closure $beforeCreate = null;

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $directories = [];


    /**
     * Returns true when $path names a file, a directory or a symbolic link, also one whose target is missing.
     */
    public static function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }


    public function create(string $path, string $content): void
    {
        $this->createDirectory(dirname($path));
        if (self::$beforeCreate !== null) {
            (self::$beforeCreate)($path);
        }

        // Mode "x" is O_CREAT|O_EXCL: the create fails when the file appeared after the check of the run.
        $file = @fopen($path, "xb");
        if ($file === false) {
            $this->fail(self::exists($path) ? "$path exists." : "Cannot create $path.");
        }
        $this->files[] = $path;
        $written       = @fwrite($file, $content);
        fclose($file);
        if ($written !== strlen($content)) {
            $this->fail("Cannot write $path.");
        }
    }


    private function createDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        $parent = dirname($directory);
        if ($parent !== $directory && !self::exists($parent)) {
            $this->createDirectory($parent);
        }
        if (!@mkdir($directory)) {
            $this->fail("Cannot create the directory $directory.");
        }
        $this->directories[] = $directory;
    }


    private function fail(string $message): never
    {
        $count = count($this->files);
        foreach ($this->files as $file) {
            @unlink($file);
        }
        foreach (array_reverse($this->directories) as $directory) {
            @rmdir($directory);
        }
        $this->files       = [];
        $this->directories = [];

        Command::failFile($message . match ($count) {
            0       => "",
            1       => " Removed the file that this run wrote.",
            default => " Removed the $count files that this run wrote.",
        });
    }
}
