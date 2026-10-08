<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

/**
 * @internal The values that PgsFormatter changes during one format() call.
 */
final class PgsContext
{
    public int $compositionNumber = 0;

    /** @var array<string, array{int, int, int}> matrix and 0xRRGGBB => [Y, Cr, Cb] */
    public array $ycrcb = [];
}
