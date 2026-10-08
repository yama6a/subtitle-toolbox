<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\JsonWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

final class JsonFormatter extends SubtitleFormatter implements ImageFormatter
{
    protected const FORMAT_OPTIONS = JsonWriteOptions::class;


    /**
     * Writes Subtitle::toArray() as JSON, with each format data string that is not valid UTF-8 as {"base64": "..."}.
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $json  = $this->formatOptions($options);
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

        return $this->applyOutputOptions(JsonOutput::document($array, $json->prettyPrint), $options);
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
