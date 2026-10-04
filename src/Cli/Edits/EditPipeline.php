<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Subtitle;

/**
 * Runs the edits of convert in one fixed order: forced cues, OCR, text, structure, timing, masking, karaoke.
 */
final class EditPipeline
{
    /**
     * Forced cues come first, so OCR reads only the cues that stay. Each parser sets the forced flag, and OCR never
     * changes it. Text edits come before structure edits, because they change the line lengths. Timing edits come after structure
     * edits, because splits create new cues. Masking comes after timing edits, so the mute ranges have the final times.
     * Karaoke multiplies the cues, so it runs last.
     *
     * @var list<class-string<Edit>>
     */
    private const EDITS = [
        ForcedEdit::class,
        OcrEdit::class,
        CommonErrorEdit::class,
        SdhEdit::class,
        ReplaceEdit::class,
        TextEdit::class,
        StructureEdit::class,
        RetimeEdit::class,
        SnapEdit::class,
        TimingFixEdit::class,
        MaskingEdit::class,
        KaraokeEdit::class,
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
        if ($arguments->has("language") && !$arguments->has("case") && !$arguments->has("fix-common-errors")) {
            Command::fail("Pass --case or --fix-common-errors with --language.");
        }

        return new self(array_values(array_filter(array_map(fn (string $edit): ?Edit => $edit::fromArguments($arguments), self::EDITS))));
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
