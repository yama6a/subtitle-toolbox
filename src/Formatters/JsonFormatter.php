<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\JsonWriteOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

final class JsonFormatter extends SubtitleFormatter implements ImageFormatter
{
    protected const FORMAT_OPTIONS = JsonWriteOptions::class;


    /**
     * Writes Subtitle::toArray() as JSON, with each format data string that is not valid UTF-8 as {"base64": "..."}.
     */
    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $json  = $this->formatOptions($options) ?? new JsonWriteOptions();
        $array = $subtitle->toArray($json->withFormatData);

        $array["metadata"] = (object)$array["metadata"];
        if (array_key_exists("formatData", $array)) {
            $array["formatData"] = $this->encodeFormatData($array["formatData"]);
        }
        foreach ($array["cues"] as $index => $cue) {
            if (array_key_exists("formatData", $cue)) {
                $array["cues"][$index]["formatData"] = $this->encodeFormatData($cue["formatData"]);
            }
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        $output = $json->prettyPrint
            ? JsonOutput::encode($array, $flags | JSON_PRETTY_PRINT) . StringHelpers::UNIX_LINE_ENDING
            : JsonOutput::encode($array, $flags);

        return $this->applyOutputOptions($output, $options);
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
