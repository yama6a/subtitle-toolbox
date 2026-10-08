<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OutputFilesTest extends TestCase
{
    /**
     * Each set: the files that the run creates in order, the file that another process creates just before the run
     * creates it, the message, and the files and directories that stay.
     *
     * @return array<string, array{list<string>, string, string, list<string>}>
     */
    public static function races(): array
    {
        return [
            "first file"             => [["new/trip.vtt"], "new/trip.vtt", "new/trip.vtt exists.", ["new", "new/trip.vtt"]],
            "second file of a batch" => [["out/trip.vtt", "out/shop.vtt"], "out/shop.vtt",
                                         "out/shop.vtt exists. Removed the file that this run wrote.", ["out", "out/shop.vtt"]],
            "file outside the directory" => [["new/radio.srt", "new/radio.edl", "radio.af"], "radio.af",
                                             "radio.af exists. Removed the 2 files that this run wrote.", ["radio.af"]],
            "nested directories"     => [["hls/deep/subs0.vtt", "hls/deep/subs.m3u8"], "hls/deep/subs.m3u8",
                                         "hls/deep/subs.m3u8 exists. Removed the file that this run wrote.", ["hls", "hls/deep", "hls/deep/subs.m3u8"]],
        ];
    }


    /**
     * @param list<string> $paths
     * @param list<string> $left
     */
    #[DataProvider("races")]
    public function testAFileThatAppearsBeforeTheCreateStopsTheRunAndRemovesItsFiles(array $paths, string $racer, string $message, array $left): void
    {
        $dir = sys_get_temp_dir() . "/subtitle-toolbox-race-" . bin2hex(random_bytes(6));
        mkdir($dir);
        $cwd = getcwd();
        chdir($dir);
        $files = new OutputFiles(function (string $path) use ($racer): void {
            if ($path === $racer) {
                file_put_contents($path, "another process");
            }
        });

        try {
            try {
                foreach ($paths as $path) {
                    $files->create($path, "subtitle");
                }
                $this->fail("The run did not stop.");
            } catch (FileFailure $failure) {
                $this->assertSame($message, $failure->getMessage());
            }

            $found = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                                                    \RecursiveIteratorIterator::SELF_FIRST) as $path => $info) {
                $found[] = substr($path, strlen("$dir/"));
            }
            sort($found);

            $this->assertSame($left, $found);
            $this->assertSame("another process", file_get_contents("$dir/$racer"));
        } finally {
            chdir($cwd);
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                                                    \RecursiveIteratorIterator::CHILD_FIRST) as $path => $info) {
                $info->isDir() ? rmdir($path) : unlink($path);
            }
            rmdir($dir);
        }
    }
}
