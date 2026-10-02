<?php

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Subtitle;

class ApplicationTest extends TestCase
{
    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, standard output, standard error
     */
    private static function runApplication(array $arguments, string $stdin = ""): array
    {
        $streams = [fopen("php://memory", "w+b"), fopen("php://memory", "w+b"), fopen("php://memory", "w+b")];
        fwrite($streams[0], $stdin);
        rewind($streams[0]);

        $code = (new Application(...$streams))->run(["subtitle-toolbox", ...$arguments]);

        rewind($streams[1]);
        rewind($streams[2]);

        return [$code, stream_get_contents($streams[1]), stream_get_contents($streams[2])];
    }


    public function testConvertsStandardInput(): void
    {
        $srt = file_get_contents(__DIR__ . "/../files/cli/trip.srt");

        $this->assertSame([0, Subtitle::parse($srt)->format(WebVttFormatter::class), ""], self::runApplication(["convert", "-", "--to", "vtt"], $srt));
    }


    public function testInfoOfStandardInput(): void
    {
        [$code, $stdout, $stderr] = self::runApplication(["info", "-", "--json"], file_get_contents(__DIR__ . "/../files/cli/shop.vtt"));

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertSame(["file" => "stdin", "format" => "vtt"], array_slice(json_decode($stdout, true), 0, 2));
    }


    public function testUsageErrorsExitWith2(): void
    {
        $this->assertSame(
            [2, "", "Error: Pass --by SECONDS.\nRun \"subtitle-toolbox help shift\" for the usage.\n"],
            self::runApplication(["shift", "-"])
        );
        $this->assertSame(
            [2, "", "Error: Pass --from RATE.\nRun \"subtitle-toolbox help fps\" for the usage.\n"],
            self::runApplication(["sync-fps", "-", "--to", "25"])
        );
    }


    public function testFileErrorsExitWith1(): void
    {
        $this->assertSame([1, "", "stdin: The format is unknown. Pass --from.\n"], self::runApplication(["info", "-"], "hello"));
        $this->assertSame([1, "", "stdin: VobSub needs the path of the .idx file. Standard input does not work.\n"],
                          self::runApplication(["info", "-", "--from", "vobsub"], "hello"));
    }
}
