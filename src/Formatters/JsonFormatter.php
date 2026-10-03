<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Options;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

class JsonFormatter extends SubtitleFormatter implements ImageFormatter
{
    public const OPTION_PRETTY_PRINT     = "prettyPrint";
    public const OPTION_WITH_FORMAT_DATA = "withFormatData";


    /**
     * Writes Subtitle::toArray() as JSON, with each format data string that is not valid UTF-8 as {"base64": "..."}.
     */
    public function format(Subtitle $subtitle, array $options = []): string
    {
        $array = $subtitle->toArray(Options::flag($options, self::OPTION_WITH_FORMAT_DATA) ?? true);

        $array["metadata"] = (object)$array["metadata"];
        if (array_key_exists("formatData", $array)) {
            $array["formatData"] = $this->encodeFormatData($array["formatData"]);
        }
        foreach ($array["cues"] as $index => $cue) {
            if (array_key_exists("formatData", $cue)) {
                $array["cues"][$index]["formatData"] = $this->encodeFormatData($cue["formatData"]);
            }
        }

        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        if (Options::flag($options, self::OPTION_PRETTY_PRINT) ?? false) {
            $json = json_encode($array, $flags | JSON_PRETTY_PRINT) . StringHelpers::UNIX_LINE_ENDING;
        } else {
            $json = json_encode($array, $flags);
        }

        return $this->applyOutputOptions($json, $options);
    }


    private function encodeFormatData(array $formatData): object
    {
        return (object)array_map(fn (array $data): object => (object)$this->encodeBinary($data), $formatData);
    }


    private function encodeBinary(mixed $value): mixed
    {
        return match (true) {
            is_array($value)                                 => array_map($this->encodeBinary(...), $value),
            is_string($value) && !preg_match('//u', $value) => ["base64" => base64_encode($value)],
            default                                          => $value,
        };
    }
}
