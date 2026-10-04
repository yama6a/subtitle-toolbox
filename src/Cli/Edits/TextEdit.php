<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Speakers\SpeakerLabelOptions;
use SubtitleToolbox\Speakers\SpeakerLabels;
use SubtitleToolbox\Speakers\SpeakerStyle;
use SubtitleToolbox\Subtitle;

final class TextEdit extends Edit
{
    private const CASES = ["upper", "lower", "sentence"];

    private const SPEAKER_MODES = ["prefix", "dashes", "colours", "from-prefix"];


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
        return "Remove tags, change the case, convert speaker labels.";
    }


    public static function options(): array
    {
        return [
            Option::flag("strip-tags", "Remove all formatting tags, such as <i> and <font>, from the cue text."),
            Option::value("case", "MODE", "Change the case of the text between tags: upper, lower or sentence."),
            Option::value("speakers", "MODE", "Convert <v> speaker tags: prefix (ANNA: Hi), dashes, colours, or from-prefix (ANNA: to <v Anna>)."),
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
            "colours"     => new SpeakerLabelOptions(to: SpeakerStyle::Colours),
            "from-prefix" => new SpeakerLabelOptions(from: SpeakerStyle::Prefix),
            null          => null,
        });
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        if ($this->stripTags) {
            $subtitle->stripFormatting();
        }
        if ($this->case !== null) {
            $subtitle->changeCase($this->case, $this->language);
        }
        if ($this->speakers !== null) {
            SpeakerLabels::apply($subtitle, $this->speakers);
        }

        return $subtitle;
    }
}
