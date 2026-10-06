<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\IttParser;
use SubtitleToolbox\Parsers\TtmlNamespaces;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;
use SubtitleToolbox\XmlLoader;

/**
 * @see https://help.apple.com/itc/videoaudioassetguide/en.lproj/static.html
 */
final class IttFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = IttWriteOptions::class;

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
     * IttWriteOptions::$frameRate wins over the frame rate of the `itt` format data.
     *
     * @throws InvalidArgumentException when neither IttWriteOptions nor the `itt` format data gives a supported frame rate.
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $fps                      = $this->formatOptions($options)?->frameRate;
        [$frameRate, $multiplier] = $this->frameRateParameters($subtitle->findFormatData(IttParser::FORMAT_DATA_KEY), $fps);
        $rate                     = new FrameRate((float) $frameRate * $this->multiplierFactor($multiplier));

        $ttml = $this->toTtmlSubtitle($subtitle);
        $xml  = (new TtmlFormatter())->format($ttml, new WriteOptions(stripTags: $options->stripTags));

        // The CLI prints a libxml warning to standard output, in front of the file.
        $document = XmlLoader::xml($xml);
        $root     = $document->documentElement;
        $root->setAttributeNS(TtmlNamespaces::PARAMETER[0], "ttp:timeBase", "smpte");
        $root->setAttributeNS(TtmlNamespaces::PARAMETER[0], "ttp:frameRate", $frameRate);
        $root->setAttributeNS(TtmlNamespaces::PARAMETER[0], "ttp:frameRateMultiplier", $multiplier);
        $root->setAttributeNS(TtmlNamespaces::PARAMETER[0], "ttp:dropMode", "nonDrop");

        $cues       = $ttml->getCues();
        $paragraphs = $document->getElementsByTagNameNS(TtmlNamespaces::TTML, "p");
        foreach ($paragraphs as $idx => $paragraph) {
            $begin = $rate->secondsToFrames(max(0.0, $cues[$idx]->getStart()));
            $end   = max($begin + 1, $rate->secondsToFrames(max(0.0, $cues[$idx]->getEnd())));
            $paragraph->setAttribute("begin", sprintf("%02d:%02d:%02d:%02d", ...Timecode::frameNumber($begin, $rate)));
            $paragraph->setAttribute("end", sprintf("%02d:%02d:%02d:%02d", ...Timecode::frameNumber($end, $rate)));
        }

        return $this->applyOutputOptions($document->saveXML(), $options);
    }


    /**
     * @return array{string, string} ttp:frameRate and ttp:frameRateMultiplier
     */
    private function frameRateParameters(array $ittData, ?float $fps): array
    {
        $stored = null;
        if (isset($ittData["frameRate"])) {
            $multiplier = $ittData["frameRateMultiplier"] ?? "1 1";
            $rate       = IttFrameRates::supported((float) $ittData["frameRate"] * $this->multiplierFactor($multiplier));
            if ($rate !== null && IttFrameRates::PARAMETERS[$rate][0] === $ittData["frameRate"]) {
                $stored = [$rate, [$ittData["frameRate"], $multiplier]];
            }
        }

        if ($fps === null) {
            return $stored[1] ?? throw new InvalidArgumentException("The ITT formatter needs IttWriteOptions with a frame rate.");
        }
        $option = IttFrameRates::supported($fps);

        // Keeps a parsed multiplier such as "1000 1001" when the option names the same frame rate.
        return $stored !== null && $stored[0] === $option ? $stored[1] : IttFrameRates::PARAMETERS[$option];
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
        $ttml->setMetadata(Subtitle::METADATA_LANGUAGE, $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $ttml->setMetadata(Subtitle::METADATA_TITLE, $subtitle->findMetadata(Subtitle::METADATA_TITLE));
        $ttml->setFormatData(TtmlParser::FORMAT_DATA_KEY, [
            "namespace"  => TtmlNamespaces::TTML,
            "namespaces" => ["ttp" => TtmlNamespaces::PARAMETER[0]],
            "head"       => self::HEAD,
            "body"       => ["style" => "normal"],
        ]);

        $copies = [];
        foreach ($subtitle->getCues() as $cue) {
            $lines = array_map(fn (string $line): string => $this->keepSupportedMarkup($line), $cue->getLines());
            $copy  = (new SubtitleCue($cue->getStart(), $cue->getEnd(), $lines))
                ->setIdentifier($cue->getIdentifier())
                ->setForced($cue->isForced());
            $copy->setFormatData(TtmlParser::FORMAT_DATA_KEY, [
                "attributes" => ["region" => in_array($cue->getAlignment(), [7, 8, 9], true) ? "top" : "bottom"],
            ]);
            $copies[] = $copy;
        }
        $ttml->addCues($copies);

        return $ttml;
    }


    private function keepSupportedMarkup(string $line): string
    {
        $line = Markup::keepTags($line, ["b", "i", "u", "font"]);

        return preg_replace_callback(
            "/<font\b([^>]*)>/i",
            function (array $matches): string {
                $color = Markup::fontColor($matches[1]);
                if ($color === null) {
                    return "<font>";
                }
                $color = strtolower(trim(Markup::decodeEntities($color)));
                if (preg_match("/^#[0-9a-f]{6}([0-9a-f]{2})?$/", $color)) {
                    return "<font color=\"" . substr($color, 0, 7) . "\">";
                }

                return in_array($color, self::NAMED_COLORS, true) ? "<font color=\"$color\">" : "<font>";
            },
            $line
        );
    }
}
