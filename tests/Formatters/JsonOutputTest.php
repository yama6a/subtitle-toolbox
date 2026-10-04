<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

class JsonOutputTest extends TestCase
{
    /**
     * @return array<string, array{Format}>
     */
    public static function jsonFormats(): array
    {
        return [
            "JSON"                      => [Format::Json],
            "Podcasting 2.0 transcript" => [Format::PodcastTranscript],
            "Podcasting 2.0 chapters"   => [Format::PodcastChapters],
        ];
    }


    #[DataProvider("jsonFormats")]
    public function testTextThatIsNotUtf8ThrowsTheLibraryException(Format $format): void
    {
        // Read without its encoding, the file keeps Latin-1 bytes that are not valid UTF-8.
        $subtitle = Subtitle::load(__DIR__ . "/../files/cli/latin1.srt", Format::SubRip);

        try {
            $subtitle->toString($format);
            $this->fail("No exception");
        } catch (InvalidArgumentException $exception) {
            $this->assertInstanceOf(JsonException::class, $exception->getPrevious());
            $this->assertSame("JSON cannot hold the subtitle: Malformed UTF-8 characters, possibly incorrectly encoded. " .
                              "Read a file in another encoding with its source encoding.", $exception->getMessage());
        }
    }
}
