<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\ParsingException;

trait ArrayConversion
{
    public const ARRAY_VERSION = 1;


    /**
     * Returns the subtitle as an array of scalars in the shape that the README section "JSON and arrays" documents.
     */
    public function toArray(bool $withFormatData = true): array
    {
        $cues = [];
        foreach ($this->cues as $cue) {
            $cueArray = [
                "start"      => $cue->getStart(),
                "end"        => $cue->getEnd(),
                "lines"      => array_values($cue->getLines()),
                "identifier" => $cue->getIdentifier(),
                "alignment"  => $cue->getAlignment(),
            ];
            if ($cue->isForced()) {
                $cueArray["forced"] = true;
            }
            if ($withFormatData) {
                $cueArray["formatData"] = $cue->getAllFormatData();
            }
            $cues[] = $cueArray;
        }

        $array = [
            "version"  => self::ARRAY_VERSION,
            "metadata" => $this->metadata,
            "comments" => $this->comments,
        ];
        if ($withFormatData) {
            $array["formatData"] = $this->formatData;
        }
        $array["cues"] = $cues;

        return $array;
    }


    /**
     * Builds a subtitle from the array that toArray() returns, keeps the cue order and throws ParsingException with the path of a bad field.
     */
    public static function fromArray(array $data): self
    {
        if (!array_key_exists("version", $data)) {
            throw new ParsingException("The field version is missing.");
        }
        if ($data["version"] !== self::ARRAY_VERSION) {
            throw new ParsingException("The field version must be " . self::ARRAY_VERSION . ".");
        }

        $subtitle = new self();
        foreach (self::arrayConversionMap($data, "metadata") as $key => $value) {
            if (!is_string($value)) {
                throw new ParsingException("The field metadata.$key must be a string.");
            }
            $subtitle->setMetadata((string)$key, $value);
        }
        foreach (self::arrayConversionFormatData($data, "formatData") as $format => $formatData) {
            $subtitle->setFormatData($format, $formatData);
        }

        if (!is_array($data["cues"] ?? null) || !array_is_list($data["cues"])) {
            throw new ParsingException("The field cues must be a list.");
        }
        foreach ($data["cues"] as $index => $cueData) {
            $subtitle->addCue(self::arrayConversionCue($cueData, "cues[$index]"), false);
        }

        foreach (self::arrayConversionList($data, "comments") as $index => $comment) {
            $path = "comments[$index]";
            if (!is_array($comment)) {
                throw new ParsingException("The field $path must be an object.");
            }
            if (!is_string($comment["text"] ?? null)) {
                throw new ParsingException("The field $path.text must be a string.");
            }
            if (!is_int($comment["beforeCueIndex"] ?? null) || $comment["beforeCueIndex"] < 0) {
                throw new ParsingException("The field $path.beforeCueIndex must be an integer of 0 or more.");
            }
            $subtitle->addComment($comment["text"], $comment["beforeCueIndex"]);
        }

        return $subtitle;
    }


    private static function arrayConversionCue(mixed $cueData, string $path): SubtitleCue
    {
        if (!is_array($cueData)) {
            throw new ParsingException("The field $path must be an object.");
        }
        foreach (["start", "end"] as $key) {
            if (!is_int($cueData[$key] ?? null) && !is_float($cueData[$key] ?? null)) {
                throw new ParsingException("The field $path.$key must be a number.");
            }
        }
        if (!is_array($cueData["lines"] ?? null) || !array_is_list($cueData["lines"])) {
            throw new ParsingException("The field $path.lines must be a list.");
        }
        foreach ($cueData["lines"] as $index => $line) {
            if (!is_string($line)) {
                throw new ParsingException("The field $path.lines[$index] must be a string.");
            }
        }

        $identifier = $cueData["identifier"] ?? null;
        if (!is_string($identifier) && $identifier !== null) {
            throw new ParsingException("The field $path.identifier must be a string or null.");
        }
        $alignment = $cueData["alignment"] ?? null;
        if ($alignment !== null && (!is_int($alignment) || $alignment < 1 || $alignment > 9)) {
            throw new ParsingException("The field $path.alignment must be an integer from 1 to 9 or null.");
        }

        $forced = $cueData["forced"] ?? false;
        if (!is_bool($forced)) {
            throw new ParsingException("The field $path.forced must be a boolean.");
        }

        $cue = (new SubtitleCue($cueData["start"], $cueData["end"], $cueData["lines"]))
            ->setIdentifier($identifier)
            ->setAlignment($alignment)
            ->setForced($forced);
        foreach (self::arrayConversionFormatData($cueData, "formatData", "$path.") as $format => $formatData) {
            $cue->setFormatData($format, $formatData);
        }

        return $cue;
    }


    /**
     * @return array<string, array>
     */
    private static function arrayConversionFormatData(array $data, string $key, string $pathPrefix = ""): array
    {
        $formatData = self::arrayConversionMap($data, $key, $pathPrefix);
        foreach ($formatData as $format => $value) {
            if (!is_array($value)) {
                throw new ParsingException("The field $pathPrefix$key.$format must be an object.");
            }
        }

        return $formatData;
    }


    private static function arrayConversionMap(array $data, string $key, string $pathPrefix = ""): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            throw new ParsingException("The field $pathPrefix$key must be an object.");
        }

        return $value;
    }


    private static function arrayConversionList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new ParsingException("The field $key must be a list.");
        }

        return $value;
    }}
