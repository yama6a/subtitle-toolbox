<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Subtitle;

/**
 * Runs the edits of convert in the order of EDITS.
 *
 * @internal
 */
final class EditPipeline
{
    /** @var list<class-string<Edit>> */
    private const EDITS = [
        ForcedEdit::class,       // The parser sets the forced flag, so OCR then reads only the cues that stay.
        OcrEdit::class,
        CommonErrorEdit::class,
        SdhEdit::class,
        ReplaceEdit::class,
        TextEdit::class,         // Text edits change the line lengths, so they run before StructureEdit.
        StructureEdit::class,    // Splits create new cues, so the timing edits run after it.
        RetimeEdit::class,
        SnapEdit::class,
        TimingFixEdit::class,
        MaskingEdit::class,      // The mute ranges need the final times.
        KaraokeEdit::class,      // It multiplies the cues, so it runs last.
    ];


    /**
     * @param list<Edit> $edits
     */
    private function __construct(private readonly array $edits)
    {
    }


    /**
     * @return list<class-string<Edit>> the edits in the order that convert runs them
     */
    public static function edits(): array
    {
        return self::EDITS;
    }


    public static function fromArguments(Arguments $arguments): self
    {
        Edit::needsOneOf($arguments, ["case", "errors-fix"], "language");

        return new self(array_values(array_filter(array_map(fn (string $edit): ?Edit => $edit::fromArguments($arguments), self::EDITS))));
    }


    /**
     * Returns false when an edit allows only one input file.
     */
    public function takesManyInputs(): bool
    {
        return array_filter($this->edits, fn (Edit $edit): bool => !$edit->takesManyInputs()) === [];
    }


    public function loadSideFiles(): void
    {
        foreach ($this->edits as $edit) {
            $edit->loadSideFiles();
        }
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        foreach ($this->edits as $edit) {
            $subtitle = $edit->apply($subtitle, $console, $label);
        }

        return $subtitle;
    }


    /**
     * @template T of Edit
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function find(string $class): ?Edit
    {
        foreach ($this->edits as $edit) {
            if ($edit instanceof $class) {
                return $edit;
            }
        }

        return null;
    }
}
