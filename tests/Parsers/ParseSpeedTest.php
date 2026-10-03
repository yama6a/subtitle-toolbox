<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\AssFormatter;
use SubtitleToolbox\Formatters\EbuStlFormatter;
use SubtitleToolbox\Formatters\MicroDvdFormatter;
use SubtitleToolbox\Formatters\SbvFormatter;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\TtmlFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class ParseSpeedTest extends TestCase
{
    private const CUE_COUNT = 20000;


    public static function formats(): array
    {
        return [
            "SubRip"   => [SubRipParser::class, SubRipFormatter::class, []],
            "WebVTT"   => [WebVttParser::class, WebVttFormatter::class, []],
            "SBV"      => [SbvParser::class, SbvFormatter::class, []],
            "ASS"      => [AssParser::class, AssFormatter::class, []],
            "MicroDVD" => [MicroDvdParser::class, MicroDvdFormatter::class, [
                MicroDvdFormatter::OPTION_FRAME_RATE            => 25,
                MicroDvdFormatter::OPTION_WRITE_FRAME_RATE_LINE => true,
            ]],
            "TTML"     => [TtmlParser::class, TtmlFormatter::class, []],
            "EBU STL"  => [EbuStlParser::class, EbuStlFormatter::class, []],
        ];
    }


    // A parser that sorts the cues after each added cue takes minutes here.
    #[DataProvider("formats")]
    public function testParsesTwentyThousandCuesUnderTenSeconds(string $parserClass, string $formatterClass, array $options): void
    {
        $content = $this->repeatFixture(self::CUE_COUNT)->format($formatterClass, $options);

        $start    = microtime(true);
        $subtitle = Subtitle::parse($content, $parserClass);

        $this->assertLessThan(10, microtime(true) - $start);
        $this->assertCount(self::CUE_COUNT, $subtitle->getCues());
        $this->assertSame([], $subtitle->getErrors());
    }


    private function repeatFixture(int $cueCount): Subtitle
    {
        $cues     = Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/real/own_escaping.srt"), SubRipParser::class)->getCues();
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
