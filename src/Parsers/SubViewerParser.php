<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class SubViewerParser extends SubtitleParser
{
    public const FORMAT = "subviewer";

    public const START_SCRIPT = "******** START SCRIPT ********";

    public const DEFAULT_LAST_CUE_DURATION = 10;

    /** Maps SubViewer header tags to the shared metadata keys of Subtitle. */
    public const METADATA_TAGS = [
        "TITLE"  => Subtitle::METADATA_TITLE,
        "AUTHOR" => Subtitle::METADATA_AUTHOR,
    ];

    private const VERSION_1_TIME_REGEX = '/^\[(\d+):(\d{2}):(\d{2})\]$/';
    private const VERSION_2_TIME_REGEX = '/^(\d+):(\d{2}):(\d{2})\.(\d{1,3}),(\d+):(\d{2}):(\d{2})\.(\d{1,3})$/';
    private const TAG_REGEX            = '/^\[([^\]]+)\](.*)$/';
    private const STYLE_TAGS           = ["[COLF]", "[SIZE]", "[FONT]", "[STYLE]"];
    private const VERSION_2_BLOCK_TAGS = ["INFORMATION", "END INFORMATION", "SUBTITLE"];

    private float $lastCueDuration;


    /**
     * Creates a parser that ends a SubViewer 1 last cue without an end line the given number of seconds after its start.
     */
    public function __construct(float $lastCueDuration = self::DEFAULT_LAST_CUE_DURATION)
    {
        if ($lastCueDuration < 0) {
            throw new InvalidArgumentException("The last cue duration must not be negative!");
        }

        $this->lastCueDuration = $lastCueDuration;
    }


    public function parse(string $rawSubtitle): Subtitle
    {
        $rawSubtitle = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawSubtitle = StringHelpers::normalizeEOLs($rawSubtitle);
        $lines       = array_map("trim", explode(StringHelpers::UNIX_LINE_ENDING, $rawSubtitle));

        $startScript = array_search(self::START_SCRIPT, $lines, true);

        return $startScript === false
            ? $this->parseVersion2($lines)
            : $this->parseVersion1(array_slice($lines, 0, $startScript), array_slice($lines, $startScript + 1, null, true));
    }


    /**
     * @param list<string> $headerLines
     * @param array<int, string> $scriptLines
     */
    private function parseVersion1(array $headerLines, array $scriptLines): Subtitle
    {
        $subtitle = new Subtitle();
        $header   = [];
        $delay    = 0;
        for ($idx = 0; $idx < count($headerLines); $idx++) {
            $line = $headerLines[$idx];
            if ($line === "") {
                continue;
            }

            if (!preg_match(self::TAG_REGEX, $line, $matches)) {
                throw new ParsingException("Line " . ($idx + 1) . " is not a SubViewer 1 header tag: $line", $idx + 1);
            }

            $tag   = strtoupper(trim($matches[1]));
            $value = trim($matches[2]);
            $next  = $headerLines[$idx + 1] ?? "";
            if ($value === "" && $next !== "" && !str_starts_with($next, "[")) {
                $value = $next;
                $idx++;
            }

            if ($tag === "DELAY") {
                // FFmpeg reads the delay as whole seconds and adds it to every time.
                $delay = (int) $value;
                $value = "0";
            }

            $this->addHeaderTag($subtitle, $header, $tag, $value);
        }

        /** @var list<SubtitleCue> $cues */
        $cues       = [];
        $hasEndLine = [];
        $afterTime  = null;
        foreach ($scriptLines as $idx => $line) {
            if ($afterTime !== null) {
                $time      = $afterTime;
                $afterTime = null;
                if ($line !== "" && !preg_match(self::VERSION_1_TIME_REGEX, $line)) {
                    $cues[]       = new SubtitleCue($time, $time, array_map(Markup::escapeText(...), explode("|", $line)));
                    $hasEndLine[] = false;
                    continue;
                }

                $last = count($cues) - 1;
                if ($last >= 0 && !$hasEndLine[$last]) {
                    $cues[$last]->setEnd($time);
                    $hasEndLine[$last] = true;
                }
            }

            if (preg_match(self::VERSION_1_TIME_REGEX, $line, $matches)) {
                $afterTime = $matches[1] * 3600 + $matches[2] * 60 + $matches[3] + $delay;
            }
        }

        if ($afterTime !== null) {
            $last = count($cues) - 1;
            if ($last >= 0 && !$hasEndLine[$last]) {
                $cues[$last]->setEnd($afterTime);
                $hasEndLine[$last] = true;
            }
        }

        foreach ($cues as $idx => $cue) {
            if (!$hasEndLine[$idx]) {
                $cue->setEnd(isset($cues[$idx + 1]) ? $cues[$idx + 1]->getStart() : $cue->getStart() + $this->lastCueDuration);
            }

            $subtitle->addCue($cue, false);
        }

        $subtitle->setFormatData(self::FORMAT, ["version" => 1, "header" => $header]);

        return $subtitle->reIndexCues();
    }


    /**
     * @param list<string> $lines
     */
    private function parseVersion2(array $lines): Subtitle
    {
        $subtitle = new Subtitle();
        $header   = [];
        $style    = null;
        $cue      = null;
        foreach ($lines as $idx => $line) {
            $lineNumber = $idx + 1;
            if ($line === "") {
                continue;
            }

            if (preg_match(self::VERSION_2_TIME_REGEX, $line, $matches)) {
                $this->addCueWithText($subtitle, $cue);
                $cue = new SubtitleCue(
                    $this->secondsFromParts($matches[1], $matches[2], $matches[3], $matches[4]),
                    $this->secondsFromParts($matches[5], $matches[6], $matches[7], $matches[8]),
                    []
                );
                continue;
            }

            // Like FFmpeg, the parser reads a style line anywhere, but keeps only the style line before the first cue.
            if ($this->isStyleLine($line)) {
                if ($cue === null) {
                    $style ??= $line;
                }
                continue;
            }

            if ($cue !== null) {
                foreach (explode("[br]", $line) as $textLine) {
                    if (trim($textLine) !== "") {
                        $cue->addLine(Markup::escapeText(trim($textLine)));
                    }
                }
                continue;
            }

            if (!preg_match(self::TAG_REGEX, $line, $matches)) {
                throw new ParsingException("Line $lineNumber is neither a header tag nor a timing line: $line", $lineNumber);
            }

            $tag = strtoupper(trim($matches[1]));
            if (!in_array($tag, self::VERSION_2_BLOCK_TAGS, true)) {
                $this->addHeaderTag($subtitle, $header, $tag, trim($matches[2]));
            }
        }
        $this->addCueWithText($subtitle, $cue);

        $subtitle->setFormatData(self::FORMAT, array_filter(
            ["version" => 2, "header" => $header, "style" => $style],
            fn ($value): bool => $value !== null
        ));

        return $subtitle->reIndexCues();
    }


    /**
     * @param array<string, string> $header
     */
    private function addHeaderTag(Subtitle $subtitle, array &$header, string $tag, string $value): void
    {
        if (array_key_exists($tag, self::METADATA_TAGS)) {
            $subtitle->setMetadata(self::METADATA_TAGS[$tag], $value === "" ? null : $value);
        } else {
            $header[$tag] = $value;
        }
    }


    private function isStyleLine(string $line): bool
    {
        if (!str_starts_with($line, "[")) {
            return false;
        }

        foreach (self::STYLE_TAGS as $tag) {
            if (str_contains($line, $tag)) {
                return true;
            }
        }

        return false;
    }


    private function addCueWithText(Subtitle $subtitle, ?SubtitleCue $cue): void
    {
        // FFmpeg makes no event for a timing line without text.
        if ($cue !== null && $cue->getLines() !== []) {
            $subtitle->addCue($cue, false);
        }
    }


    private function secondsFromParts(string $hours, string $minutes, string $seconds, string $fraction): float
    {
        return (int) $hours * 3600 + (int) $minutes * 60 + (int) $seconds + (float) ("0." . $fraction);
    }
}
