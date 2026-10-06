<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\CaseMode;
use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Speakers\SpeakerLabelOptions;
use SubtitleToolbox\Speakers\SpeakerLabels;
use SubtitleToolbox\Speakers\SpeakerStyle;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class TextEdit extends Edit
{
    private const CASES = ["upper", "lower", "sentence"];

    private const SPEAKER_MODES = ["prefix", "dashes", "colors", "from-prefix"];


    private function __construct(
        private readonly bool $stripTags,
        private readonly ?string $case,
        private readonly ?string $language,
        private readonly ?SpeakerLabelOptions $speakers,
    ) {
    }


    public static function group(): string
    {
        return "text";
    }


    public static function summary(): string
    {
        return "Convert speaker labels, change the case, remove tags.";
    }


    public static function options(): array
    {
        return [
            Option::value("speakers", "MODE", "Convert <v> speaker tags: prefix (ANNA: Hi), dashes, colors, or from-prefix (ANNA: to <v Anna>)."),
            Option::value("case", "MODE", "Change the case of the text between tags: upper, lower or sentence."),
            Option::flag("strip-tags", "Remove all formatting tags, such as <i> and <font>, from the cue text."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        $case = $arguments->value("case");
        if ($case !== null && !in_array($case, self::CASES, true)) {
            Command::fail("Unknown case \"$case\". Known cases: " . implode(", ", self::CASES) . ".");
        }
        $speakers = $arguments->value("speakers");
        if ($speakers !== null && !in_array($speakers, self::SPEAKER_MODES, true)) {
            Command::fail("Unknown speaker mode \"$speakers\". Known modes: " . implode(", ", self::SPEAKER_MODES) . ".");
        }
        if (!$arguments->has("strip-tags") && $case === null && $speakers === null) {
            return null;
        }

        return new self($arguments->has("strip-tags"), $case, $arguments->value("language"), match ($speakers) {
            "prefix"      => new SpeakerLabelOptions(to: SpeakerStyle::Prefix),
            "dashes"      => new SpeakerLabelOptions(to: SpeakerStyle::DialogueDashes),
            "colors"     => new SpeakerLabelOptions(to: SpeakerStyle::Colors),
            "from-prefix" => new SpeakerLabelOptions(readPrefixes: true),
            null          => null,
        });
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        if ($this->speakers !== null) {
            SpeakerLabels::apply($subtitle, $this->speakers);
        }
        if ($this->case !== null) {
            $subtitle->changeCase(CaseMode::from($this->case), $this->language);
        }
        if ($this->stripTags) {
            $subtitle->stripFormatting();
        }

        return $subtitle;
    }
}
