<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use JsonException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

class JsonParser extends SubtitleParser
{
    private const PLACEHOLDER_CUE = ["start" => 0, "end" => 0, "lines" => []];

    /**
     * Reads the JSON that JsonFormatter writes and throws ParsingException with the path of a bad field, for example cues[3].start.
     */
    protected function read(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        try {
            $data = json_decode(StringHelpers::removeUtf8Bom($rawSubtitle), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ParsingException("The content is not valid JSON: {$exception->getMessage()}.");
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ParsingException("The JSON root must be an object.");
        }

        foreach (is_array($data["formatData"] ?? null) ? $data["formatData"] : [] as $format => $formatData) {
            if (!is_array($formatData)) {
                continue;
            }
            try {
                $data["formatData"][$format] = $this->decodeBinary($formatData, "formatData.$format");
            } catch (ParsingException $exception) {
                $this->fail($exception, 0, -1, [RawJson::encode([$format => $formatData])]);
                unset($data["formatData"][$format]);
            }
        }
        $skipped = [];
        if (is_array($data["cues"] ?? null)) {
            foreach ($data["cues"] as $index => $cue) {
                if (is_array($cue["formatData"] ?? null)) {
                    try {
                        $data["cues"][$index]["formatData"] = $this->decodeBinary($cue["formatData"], "cues[$index].formatData");
                    } catch (ParsingException $exception) {
                        $this->fail($exception, 0, $index, [RawJson::encode($cue)]);
                        $data["cues"][$index] = self::PLACEHOLDER_CUE;
                        $skipped[$index]      = true;
                    }
                }
            }
        }

        return $this->lenient ? $this->fromArraySkippingBrokenCues($data, $skipped) : Subtitle::fromArray($data);
    }


    /**
     * Puts a placeholder in place of each cue that fromArray() rejects, so the error paths keep the cue numbers of the file.
     * Drops a bad metadata field, a bad comment and the bad format data of one format with a warning of block index -1.
     *
     * @param array<int, true> $skipped
     */
    private function fromArraySkippingBrokenCues(array $data, array $skipped): Subtitle
    {
        $skippedComments = [];
        while (true) {
            try {
                Subtitle::fromArray($data);
                break;
            } catch (ParsingException $exception) {
                $message = $exception->getMessage();
                if (preg_match('/: The field cues\[(\d+)\]/', $message, $matches)) {
                    $index = (int) $matches[1];
                    $this->fail($exception, 0, $index, [RawJson::encode($data["cues"][$index])]);
                    $data["cues"][$index] = self::PLACEHOLDER_CUE;
                    $skipped[$index]      = true;
                } elseif (preg_match('/: The field comments\[(\d+)\]/', $message, $matches)) {
                    $index = (int) $matches[1];
                    $this->fail($exception, 0, -1, [RawJson::encode($data["comments"][$index])]);
                    $data["comments"][$index] = ["text" => "", "beforeCueIndex" => 0];
                    $skippedComments[$index]  = true;
                } elseif (preg_match('/: The field (metadata|formatData)\.(.+?) (?:must|is) /', $message, $matches)
                          && is_array($data[$matches[1]] ?? null)) {
                    $key = $this->topLevelKey($data[$matches[1]], $matches[2]);
                    if ($key === null) {
                        throw $exception;
                    }
                    $this->fail($exception, 0, -1, [RawJson::encode([$key => $data[$matches[1]][$key]])]);
                    unset($data[$matches[1]][$key]);
                } else {
                    throw $exception;
                }
            }
        }

        usort($this->warnings, fn (ParseWarning $warning1, ParseWarning $warning2): int => $warning1->blockIndex <=> $warning2->blockIndex);
        $data["cues"] = array_values(array_diff_key($data["cues"], $skipped));
        if ($skippedComments !== []) {
            $data["comments"] = array_values(array_diff_key($data["comments"], $skippedComments));
        }
        foreach (is_array($data["comments"] ?? null) ? $data["comments"] : [] as $index => $comment) {
            if (is_int($comment["beforeCueIndex"] ?? null)) {
                $before = $comment["beforeCueIndex"];
                $data["comments"][$index]["beforeCueIndex"] -= count(array_filter(array_keys($skipped), fn (int $cue): bool => $cue < $before));
            }
        }

        return Subtitle::fromArray($data);
    }


    /**
     * Returns the key of $map that the start of $path names. A key may hold dots, so the longest match wins.
     */
    private function topLevelKey(array $map, string $path): int|string|null
    {
        $found = null;
        foreach (array_keys($map) as $key) {
            if (((string) $key === $path || str_starts_with($path, "$key.") || str_starts_with($path, "{$key}["))
                && ($found === null || strlen((string) $key) > strlen((string) $found))) {
                $found = $key;
            }
        }

        return $found;
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
