<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\Matroska;

/**
 * MatroskaTrack describes one subtitle track of an MKV file.
 */
final class MatroskaTrack
{
    /**
     * @internal
     *
     * @param string $language the LanguageBCP47 element, else the Language element, else "eng"
     */
    public function __construct(
        public readonly int $number,
        public readonly string $codecId,
        public readonly string $language,
        public readonly ?string $name,
        public readonly bool $default,
        public readonly bool $forced,
    ) {
    }


    /**
     * Returns the codec, the language, the name and the flags, for example `S_TEXT/UTF8, de, "Deutsch", default`.
     *
     * @internal
     */
    public function describe(): string
    {
        return implode(", ", array_filter([
            $this->codecId,
            $this->language,
            $this->name === null ? null : json_encode($this->name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            $this->default ? "default" : null,
            $this->forced ? "forced" : null,
        ]));
    }
}
