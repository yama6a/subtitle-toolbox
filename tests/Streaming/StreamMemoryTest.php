<?php

namespace SubtitleToolbox\Streaming;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\SubtitleCue;

class StreamMemoryTest extends TestCase
{
    private const CUE_COUNT = 200000;

    private const MEMORY_LIMIT = 2 * 1024 * 1024;


    public static function formats(): array
    {
        return [
            "SubRip" => [fn ($stream) => new SubRipStreamWriter($stream), new SubRipStreamReader()],
            "WebVTT" => [fn ($stream) => new WebVttStreamWriter($stream), new WebVttStreamReader()],
        ];
    }


    #[DataProvider("formats")]
    public function testStreamsManyCuesInConstantMemory(callable $createWriter, CueStreamReader $reader): void
    {
        $stream = tmpfile();
        $before = memory_get_usage();
        memory_reset_peak_usage();

        $writer = $createWriter($stream);
        for ($index = 0; $index < self::CUE_COUNT; $index++) {
            $writer->write(new SubtitleCue($index * 2, $index * 2 + 1.5, "Light event number $index\n<i>Zone</i> " . $index % 7));
        }
        $writer->close();
        $this->assertLessThan(self::MEMORY_LIMIT, memory_get_peak_usage() - $before, "write");
        $this->assertGreaterThan(10 * 1024 * 1024, ftell($stream));

        rewind($stream);
        memory_reset_peak_usage();
        $count = 0;
        foreach ($reader->read($stream) as $cue) {
            $count++;
        }
        $this->assertLessThan(self::MEMORY_LIMIT, memory_get_peak_usage() - $before, "read");
        $this->assertSame(self::CUE_COUNT, $count);
        $this->assertSame(399998.0, $cue->getStart());
        $this->assertSame("Light event number 199999\n<i>Zone</i> 2", $cue->getText());

        fclose($stream);
    }
}
