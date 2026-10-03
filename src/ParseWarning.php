<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\ParsingException;

final class ParseWarning
{
    public const SKIPPED  = "skipped";
    public const REPAIRED = "repaired";


    /**
     * Holds one problem that a lenient parser found and what it did about it.
     *
     * @param int          $lineNumber 1-based input line of the problem
     * @param int          $blockIndex 0-based number of the block, as in the "Block #n" messages
     * @param list<string> $block      the lines of the block, trimmed as the parser reads them
     * @param string       $action     self::SKIPPED or self::REPAIRED
     */
    public function __construct(
        public readonly string $message,
        public readonly int $lineNumber,
        public readonly int $blockIndex,
        public readonly array $block,
        public readonly string $action,
    ) {
    }


    /**
     * Returns the warning for a block that the parser skipped, with the message of $exception without its class prefix.
     *
     * @param list<string> $block
     */
    public static function skipped(ParsingException $exception, int $lineNumber, int $blockIndex, array $block): self
    {
        $message = preg_replace('/^ParsingException \(Error #\d+\): /', "", $exception->getMessage());

        return new self($message, $lineNumber, $blockIndex, $block, self::SKIPPED);
    }
}
