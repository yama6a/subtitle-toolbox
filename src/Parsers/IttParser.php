<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Subtitle;

class IttParser extends TtmlParser
{
    public const FORMAT = "itt";

    private const TIMING_PARAMETERS = ["timeBase", "frameRate", "frameRateMultiplier", "dropMode"];


    /**
     * Parses the file as TTML and copies the SMPTE timing parameters of the root element to the `itt` format data.
     */
    protected function read(string $rawSubtitle): Subtitle
    {
        $subtitle = parent::read($rawSubtitle);
        $ttmlData = $subtitle->getFormatData(TtmlParser::FORMAT);

        $parameters = [];
        foreach ($ttmlData["attributes"] ?? [] as $name => $value) {
            [$prefix, $localName] = str_contains($name, ":") ? explode(":", $name, 2) : ["", $name];
            $namespace            = $ttmlData["namespaces"][$prefix] ?? null;
            if (in_array($localName, self::TIMING_PARAMETERS, true)
                && (in_array($namespace, self::PARAMETER_NAMESPACES, true) || $namespace === null && $prefix === "ttp")) {
                $parameters[$localName] = trim($value);
            }
        }
        $parameters = array_intersect_key(array_replace(array_flip(self::TIMING_PARAMETERS), $parameters), $parameters);
        if ($parameters !== []) {
            $subtitle->setFormatData(self::FORMAT, $parameters);
        }

        return $subtitle;
    }
}
