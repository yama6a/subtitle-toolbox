<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

final class CommonErrorReport
{
    /**
     * @internal CommonErrorFixer::apply() and preview() create the report.
     *
     * @param list<AppliedFix> $fixes each change in the order of the cues and of the CommonErrorRule cases
     */
    public function __construct(
        public readonly array $fixes,
    ) {
    }
}
