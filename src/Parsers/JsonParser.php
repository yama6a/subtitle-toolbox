<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\Subtitle;

final class JsonParser extends SubtitleParser
{
    /**
     * Reads the JSON that JsonFormatter writes and throws ParsingException with the path of a bad field, for example cues[3].start.
     */
    protected function read(string $rawSubtitle): Subtitle
    {
        $data = $this->decodeJsonObject($rawSubtitle);

        foreach (is_array($data["formatData"] ?? null) ? $data["formatData"] : [] as $format => $formatData) {
            if (!is_array($formatData)) {
                continue;
            }
            try {
                $data["formatData"][$format] = $this->decodeBinary($formatData, "formatData.$format");
            } catch (ParsingException $exception) {
                $this->fail($exception, null, null, [RawJson::encode([$format => $formatData])]);
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
                        $this->fail($exception, null, $index, [RawJson::encode($cue)]);
                        $skipped[$index] = true;
                    }
                }
            }
        }

        $reject   = function (ParsingException $exception, string $field, int|string $key) use ($data): void {
            $entry = $data[$field][$key];
            $block = $field === "cues" || $field === "comments" ? $entry : [$key => $entry];
            $this->fail($exception, null, $field === "cues" ? $key : null, [RawJson::encode($block)]);
        };
        $subtitle = Subtitle::fromArrayLeavingOut($data, $skipped, $reject);
        usort($this->warnings, fn (ParseWarning $warning1, ParseWarning $warning2): int => $warning1->blockIndex <=> $warning2->blockIndex);

        return $subtitle;
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
