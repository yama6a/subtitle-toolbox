<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container;

use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
interface ContainerReader
{
    /**
     * @return list<SubtitleTrack>
     */
    public function getSubtitleTracks(): array;


    public function trackFormat(int $trackNumber): ?Format;


    public function extract(int $trackNumber, ?ReadOptions $options = null): Subtitle;
}
