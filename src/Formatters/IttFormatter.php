<?php

namespace SubtitleToolbox\Formatters;

use DOMDocument;
use InvalidArgumentException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\IttParser;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * @see https://help.apple.com/itc/videoaudioassetguide/en.lproj/static.html
 */
class IttFormatter extends SubtitleFormatter
{
    public const OPTION_FRAME_RATE = "OPTION_FRAME_RATE";

    /** frames per second => [ttp:frameRate, ttp:frameRateMultiplier] */
    private const FRAME_RATES = [
        "23.976" => ["24", "999 1000"],
        "24"     => ["24", "1 1"],
        "25"     => ["25", "1 1"],
        "29.97"  => ["30", "999 1000"],
        "30"     => ["30", "1 1"],
    ];

    private const HEAD = "<head>\n"
                         . "    <styling>\n"
                         . "      <style xml:id=\"normal\" tts:fontFamily=\"sansSerif\" tts:fontWeight=\"normal\" tts:fontStyle=\"normal\""
                         . " tts:color=\"white\" tts:fontSize=\"100%\"/>\n"
                         . "    </styling>\n"
                         . "    <layout>\n"
                         . "      <region xml:id=\"top\" tts:origin=\"0% 0%\" tts:extent=\"100% 15%\" tts:textAlign=\"center\""
                         . " tts:displayAlign=\"before\"/>\n"
                         . "      <region xml:id=\"bottom\" tts:origin=\"0% 85%\" tts:extent=\"100% 15%\" tts:textAlign=\"center\""
                         . " tts:displayAlign=\"after\"/>\n"
                         . "    </layout>\n"
                         . "  </head>";

    // TTML 1, section 8.3.13, named colors.
    private const NAMED_COLORS = [
        "black", "silver", "gray", "white", "maroon", "red", "purple", "fuchsia", "magenta",
        "green", "lime", "olive", "yellow", "navy", "blue", "teal", "aqua", "cyan",
    ];


    /**
     * Writes an Apple iTunes Timed Text file with SMPTE times, one div, and a top and a bottom region.
     *
     * @throws InvalidArgumentException when neither the `itt` format data nor OPTION_FRAME_RATE gives a supported frame rate.
     */
    public function format(Subtitle $subtitle, array $options = []): string
    {
        [$frameRate, $multiplier] = $this->frameRateParameters($subtitle->getFormatData(IttParser::FORMAT), $options);
        $fps                      = (float) $frameRate * $this->multiplierFactor($multiplier);

        $ttml = $this->toTtmlSubtitle($subtitle);
        $xml  = (new TtmlFormatter())->format($ttml, array_diff_key($options, [self::OPTION_LINE_ENDING => 0, self::OPTION_BOM => 0]));

        $document = new DOMDocument();
        $document->loadXML($xml, LIBXML_NONET);
        $root = $document->documentElement;
        $root->setAttributeNS(TtmlParser::PARAMETER_NAMESPACES[0], "ttp:timeBase", "smpte");
        $root->setAttributeNS(TtmlParser::PARAMETER_NAMESPACES[0], "ttp:frameRate", $frameRate);
        $root->setAttributeNS(TtmlParser::PARAMETER_NAMESPACES[0], "ttp:frameRateMultiplier", $multiplier);
        $root->setAttributeNS(TtmlParser::PARAMETER_NAMESPACES[0], "ttp:dropMode", "nonDrop");

        $cues       = $ttml->getCues();
        $paragraphs = $document->getElementsByTagNameNS(TtmlParser::NAMESPACE_TTML, "p");
        foreach ($paragraphs as $idx => $paragraph) {
            $begin = $this->frameIndex($cues[$idx]->getStart(), (int) $frameRate, $fps);
            $end   = max($begin + 1, $this->frameIndex($cues[$idx]->getEnd(), (int) $frameRate, $fps));
            $paragraph->setAttribute("begin", $this->formatFrameIndex($begin, (int) $frameRate));
            $paragraph->setAttribute("end", $this->formatFrameIndex($end, (int) $frameRate));
        }

        return $this->applyOutputOptions($document->saveXML(), $options);
    }


    /**
     * @return array{string, string} ttp:frameRate and ttp:frameRateMultiplier
     */
    private function frameRateParameters(array $ittData, array $options): array
    {
        if (isset($ittData["frameRate"])) {
            $multiplier = $ittData["frameRateMultiplier"] ?? "1 1";
            $stored     = $this->supportedFrameRate((float) $ittData["frameRate"] * $this->multiplierFactor($multiplier));
            if ($stored !== null && self::FRAME_RATES[$stored][0] === $ittData["frameRate"]) {
                return [$ittData["frameRate"], $multiplier];
            }
        }

        if (!isset($options[self::OPTION_FRAME_RATE])) {
            throw new InvalidArgumentException("The ITT formatter needs the option " . self::OPTION_FRAME_RATE . ".");
        }
        $option = $this->supportedFrameRate((float) $options[self::OPTION_FRAME_RATE]);
        if ($option === null) {
            throw new InvalidArgumentException(
                "The ITT formatter accepts the frame rates 23.976, 24, 25, 29.97 and 30, got {$options[self::OPTION_FRAME_RATE]}."
            );
        }

        return self::FRAME_RATES[$option];
    }


    private function supportedFrameRate(float $fps): ?string
    {
        foreach (array_keys(self::FRAME_RATES) as $supported) {
            if (abs($fps - (float) $supported) < 0.01) {
                return (string) $supported;
            }
        }

        return null;
    }


    private function multiplierFactor(string $multiplier): float
    {
        $parts = preg_split("/[\s:]+/", trim($multiplier));

        return count($parts) === 2 && (float) $parts[0] > 0 && (float) $parts[1] > 0 ? (float) $parts[0] / (float) $parts[1] : 1.0;
    }


    /**
     * Copies the cues with the Apple regions and the core markup that ITT supports, so that TtmlFormatter writes the body.
     */
    private function toTtmlSubtitle(Subtitle $subtitle): Subtitle
    {
        $ttml = new Subtitle();
        $ttml->setMetadata(Subtitle::METADATA_LANGUAGE, $subtitle->getMetadata(Subtitle::METADATA_LANGUAGE));
        $ttml->setMetadata(Subtitle::METADATA_TITLE, $subtitle->getMetadata(Subtitle::METADATA_TITLE));
        $ttml->setFormatData(TtmlParser::FORMAT, [
            "namespace"  => TtmlParser::NAMESPACE_TTML,
            "namespaces" => ["ttp" => TtmlParser::PARAMETER_NAMESPACES[0]],
            "head"       => self::HEAD,
            "body"       => ["style" => "normal"],
        ]);

        foreach ($subtitle->getCues() as $cue) {
            $lines = array_map(fn (string $line): string => $this->keepSupportedMarkup($line), $cue->getLines());
            $copy  = (new SubtitleCue($cue->getStart(), $cue->getEnd(), $lines))->setIdentifier($cue->getIdentifier());
            $copy->setFormatData(TtmlParser::FORMAT, [
                "attributes" => ["region" => in_array($cue->getAlignment(), [7, 8, 9], true) ? "top" : "bottom"],
            ]);
            $ttml->addCue($copy, false);
        }
        $ttml->reIndexCues();

        return $ttml;
    }


    private function keepSupportedMarkup(string $line): string
    {
        $line = Markup::keepTags($line, ["b", "i", "u", "font"]);

        return preg_replace_callback(
            "/<font\b([^>]*)>/i",
            function (array $matches): string {
                if (!preg_match("/\bcolor\s*=\s*(?:\"([^\"]*)\"|'([^']*)'|([^\s\"']+))/i", $matches[1], $color)) {
                    return "<font>";
                }
                $color = strtolower(trim(Markup::decodeEntities(($color[1] ?? "") . ($color[2] ?? "") . ($color[3] ?? ""))));
                if (preg_match("/^#[0-9a-f]{6}([0-9a-f]{2})?$/", $color)) {
                    return "<font color=\"" . substr($color, 0, 7) . "\">";
                }

                return in_array($color, self::NAMED_COLORS, true) ? "<font color=\"$color\">" : "<font>";
            },
            $line
        );
    }


    /**
     * Rounds to the nearest frame of the hh:mm:ss:ff grid that TtmlParser reads: whole seconds plus frames at $fps.
     */
    private function frameIndex(float $seconds, int $framesPerSecond, float $fps): int
    {
        $seconds = max(0.0, $seconds);
        $whole   = (int) floor($seconds);
        $frames  = (int) round(($seconds - $whole) * $fps);
        if ($frames >= $framesPerSecond) {
            return ($whole + 1) * $framesPerSecond;
        }

        return $whole * $framesPerSecond + $frames;
    }


    private function formatFrameIndex(int $index, int $framesPerSecond): string
    {
        $seconds = intdiv($index, $framesPerSecond);

        return sprintf(
            "%02d:%02d:%02d:%02d",
            intdiv($seconds, 3600),
            intdiv($seconds, 60) % 60,
            $seconds % 60,
            $index % $framesPerSecond
        );
    }
}
