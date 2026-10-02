<?php

namespace SubtitleToolbox\Parsers;

use JsonException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

class JsonParser extends SubtitleParser
{
    /**
     * Reads the JSON that JsonFormatter writes and throws ParsingException with the path of a bad field, for example cues[3].start.
     */
    public function parse(string $rawSubtitle): Subtitle
    {
        try {
            $data = json_decode(StringHelpers::removeUtf8Bom($rawSubtitle), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ParsingException("The content is not valid JSON: {$exception->getMessage()}.");
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ParsingException("The JSON root must be an object.");
        }

        if (is_array($data["formatData"] ?? null)) {
            $data["formatData"] = $this->decodeBinary($data["formatData"], "formatData");
        }
        if (is_array($data["cues"] ?? null)) {
            foreach ($data["cues"] as $index => $cue) {
                if (is_array($cue["formatData"] ?? null)) {
                    $data["cues"][$index]["formatData"] = $this->decodeBinary($cue["formatData"], "cues[$index].formatData");
                }
            }
        }

        return Subtitle::fromArray($data);
    }


    private function decodeBinary(array $value, string $path): array|string
    {
        if (array_keys($value) === ["base64"] && is_string($value["base64"])) {
            $bytes = base64_decode($value["base64"], true);
            if ($bytes === false) {
                throw new ParsingException("The field $path.base64 must be valid base64.");
            }

            return $bytes;
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->decodeBinary($item, is_int($key) ? "{$path}[$key]" : "$path.$key");
            }
        }

        return $value;
    }
}
