<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\FrameRate;

/**
 * The column layout of a CSV or TSV table. A string maps a role to a header name, case-insensitively.
 * An int maps it to a 0-based column index. Null maps it to the header with the role name, when the table has one.
 */
class CsvColumns
{
    public const ROLES = ["identifier", "start", "end", "duration", "speaker", "text"];


    public function __construct(
        public readonly string|int|null $start = null,
        public readonly string|int|null $end = null,
        public readonly string|int|null $text = null,
        public readonly string|int|null $speaker = null,
        public readonly string|int|null $identifier = null,
        public readonly string|int|null $duration = null,
        public readonly ?float $frameRate = null,
        public readonly bool $header = true,
    ) {
        if ($frameRate !== null) {
            new FrameRate($frameRate);
        }

        foreach (self::ROLES as $role) {
            $column = $this->$role;
            if (is_int($column) && $column < 0) {
                throw new InvalidArgumentException("The column index of $role must be 0 or more, got $column.");
            }
            if (!$header && !is_int($column) && ($column !== null || $role === "start" || $role === "text")) {
                throw new InvalidArgumentException("A table without a header row needs a column index for $role.");
            }
        }
    }
}
