<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MicroDvdOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Validation\ValidationRules;
use SubtitleToolbox\WriteOptions;

class ParseSpeedTest extends TestCase
{
    private const CUE_COUNT = 20000;


    public static function formats(): array
    {
        return [
            "SubRip"   => [Format::SubRip, new WriteOptions()],
            "WebVTT"   => [Format::WebVtt, new WriteOptions()],
            "SBV"      => [Format::Sbv, new WriteOptions()],
            "ASS"      => [Format::Ass, new WriteOptions()],
            "MicroDVD" => [Format::MicroDvd, new WriteOptions(format: new MicroDvdOptions(frameRate: 25, writeFrameRateLine: true))],
            "TTML"     => [Format::Ttml, new WriteOptions()],
            "EBU STL"  => [Format::EbuStl, new WriteOptions()],
        ];
    }


    // A parser that sorts the cues after each added cue takes minutes here.
    #[DataProvider("formats")]
    public function testParsesTwentyThousandCuesUnderTenSeconds(Format $format, WriteOptions $options): void
    {
        $content = $this->repeatFixture(self::CUE_COUNT)->toString($format, $options);

        $start    = microtime(true);
        $subtitle = Subtitle::fromString($content, $format);

        $this->assertLessThan(10, microtime(true) - $start);
        $this->assertCount(self::CUE_COUNT, $subtitle->getCues());
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }


    private function repeatFixture(int $cueCount): Subtitle
    {
        $cues     = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/real/own_escaping.srt"), Format::SubRip)->getCues();
        $period   = ceil(end($cues)->getEnd()) + 1;
        $subtitle = new Subtitle();
        for ($index = 0; $index < $cueCount; $index++) {
            $cue    = $cues[$index % count($cues)];
            $offset = intdiv($index, count($cues)) * $period;
            $subtitle->addCue(new SubtitleCue($cue->getStart() + $offset, $cue->getEnd() + $offset, $cue->getLines()), false);
        }

        return $subtitle;
    }
}
