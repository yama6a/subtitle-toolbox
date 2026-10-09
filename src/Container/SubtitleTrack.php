<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container;

use SubtitleToolbox\Format;

/**
 * SubtitleTrack describes one subtitle track of a video container file.
 */
final readonly class SubtitleTrack
{
    /**
     * @internal
     *
     * @param string      $codecId  the codec as the container writes it, for example "S_TEXT/UTF8"
     * @param Format|null $format   the format that loadTrack() reads the track as, or null for a codec that it does not read
     * @param string      $language the language code of the track, or the default language of the container spec
     */
    public function __construct(
        public ContainerFormat $container,
        public int $number,
        public string $codecId,
        public ?Format $format,
        public string $language,
        public ?string $name,
        public bool $default,
        public bool $forced,
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
