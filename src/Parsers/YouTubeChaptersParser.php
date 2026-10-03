<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class YouTubeChaptersParser extends SubtitleParser
{
    // A space, "|", ":", "-", an en dash or an em dash. The dashes are UTF-8 bytes, so the patterns need no /u flag.
    private const SEPARATOR       = '(?:[\s|:-]|\xE2\x80[\x93\x94])';
    private const AFTER_SEPARATOR = '(?<=^|[\s|:-]|\xE2\x80\x93|\xE2\x80\x94)';
    private const TIME            = '[(\[]?(?:(\d+):)?(\d+):(\d{2})[)\]]?';


    /**
     * Creates a parser that ends the last chapter at $mediaDuration seconds, or at its own start when it is null.
     */
    public function __construct(private readonly ?float $mediaDuration = null)
    {
    }


    /**
     * Reads the lines of $rawSubtitle that start or end with a time such as 2:48 or 1:02:48, for example a video description.
     */
    public function parse(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        $lines          = explode("\n", StringHelpers::normalizeEOLs(StringHelpers::removeUtf8Bom($rawSubtitle)));

        $chapters = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^' . self::TIME . '(?=$|' . self::SEPARATOR . ')(.*)$/', $line, $matches) === 1) {
                [, $hours, $minutes, $seconds, $title] = $matches;
            } elseif (preg_match('/^(.*?)' . self::AFTER_SEPARATOR . self::TIME . '$/', $line, $matches) === 1) {
                [, $title, $hours, $minutes, $seconds] = $matches;
            } else {
                continue;
            }
            if ((int) $seconds > 59 || ($hours !== "" && (int) $minutes > 59)) {
                continue;
            }

            $title      = preg_replace('/^' . self::SEPARATOR . '+|' . self::SEPARATOR . '+$/', "", $title);
            $chapters[] = new SubtitleCue((int) $hours * 3600 + (int) $minutes * 60 + (int) $seconds, 0, Markup::escapeText($title));
        }

        usort($chapters, fn (SubtitleCue $a, SubtitleCue $b): int => $a->getStart() <=> $b->getStart());
        $subtitle = new Subtitle();
        foreach ($chapters as $index => $cue) {
            $cue->setEnd(isset($chapters[$index + 1]) ? $chapters[$index + 1]->getStart() : max($cue->getStart(), $this->mediaDuration ?? 0));
            $subtitle->addCue($cue, false);
        }

        return $subtitle;
    }
}
