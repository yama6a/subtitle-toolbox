<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

/**
 * The default fields of the ASS and SSA "Format:" lines, which AssParser, AssFormatter and MatroskaReader share.
 *
 * @internal
 */
final class AssFormatLines
{
    public const ASS_STYLE_FORMAT = [
        "Name", "Fontname", "Fontsize", "PrimaryColour", "SecondaryColour", "OutlineColour", "BackColour",
        "Bold", "Italic", "Underline", "StrikeOut", "ScaleX", "ScaleY", "Spacing", "Angle",
        "BorderStyle", "Outline", "Shadow", "Alignment", "MarginL", "MarginR", "MarginV", "Encoding",
    ];

    public const SSA_STYLE_FORMAT = [
        "Name", "Fontname", "Fontsize", "PrimaryColour", "SecondaryColour", "TertiaryColour", "BackColour",
        "Bold", "Italic", "BorderStyle", "Outline", "Shadow", "Alignment", "MarginL", "MarginR", "MarginV",
        "AlphaLevel", "Encoding",
    ];

    public const ASS_EVENT_FORMAT = ["Layer", "Start", "End", "Style", "Name", "MarginL", "MarginR", "MarginV", "Effect", "Text"];

    public const SSA_EVENT_FORMAT = ["Marked", "Start", "End", "Style", "Name", "MarginL", "MarginR", "MarginV", "Effect", "Text"];
}
