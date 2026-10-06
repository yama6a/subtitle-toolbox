<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Sync\ReferenceSync;
use SubtitleToolbox\Sync\ReferenceSyncOptions;
use SubtitleToolbox\Sync\SpeechReference;

/**
 * @internal
 */
final class SyncCommand extends WriteCommand
{
    private const LOW_SCORE = 0.5;

    private ?ReferenceSyncOptions $syncOptions = null;

    private Subtitle $reference;


    public function name(): string
    {
        return "sync";
    }


    public function summary(): string
    {
        return "Finds the offset and frame-rate scale against a reference subtitle or the speech, and retimes the input.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --reference FILE [options]", "<input>... --silence-log FILE --media-duration SECONDS [options]"];
    }


    protected function details(): string
    {
        return "Only the cue times count, so the reference can be in another language. The scale is 1 or a factor\n" .
               "between 23.976, 24 and 25 fps. The tool prints the scale, the offset and a score from 0 to 1. A score\n" .
               "below 0.5 means that the files likely do not match. One input file goes to standard output, or to the file\n" .
               "of -o. Several input files need --output-dir.\n" .
               "For a sync to the speech, run ffmpeg -i movie.mkv -af silencedetect=noise=-30dB:d=0.4 -f null - 2> silence.log\n" .
               "and pass --silence-log silence.log. A Whisper JSON transcript of the audio also works as --reference.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("reference", "FILE", "Subtitle in sync with the video, in any format that the tool reads."),
            Option::value("silence-log", "FILE", "Log of the FFmpeg silencedetect filter. The speech between the silences is the reference."),
            Option::value("media-duration", "SECONDS", "Duration of the video, for --silence-log."),
            Option::value("min-offset", "SECONDS", "Smallest offset to try. Default: -60."),
            Option::value("max-offset", "SECONDS", "Largest offset to try. Default: 60."),
            Option::flag("no-scale", "Keep the scale at 1 and find only the offset."),
            Option::value("max-splits", "SPLITS", "Find up to this many points where the offset jumps, for example at ad breaks. Default: 0."),
            Option::value("split-penalty", "SCORE", "Score that a split must add to stay. Default: 0.1."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->checkReference($arguments);

        $maxSplits = $arguments->value("max-splits") ?? "0";
        if (!ctype_digit($maxSplits)) {
            self::fail("The option --max-splits needs a whole number, got \"$maxSplits\".");
        }
        if (strlen(ltrim($maxSplits, "0")) > 2 || (int)$maxSplits > ReferenceSyncOptions::MAX_SPLITS) {
            self::fail("The option --max-splits must be from 0 to " . ReferenceSyncOptions::MAX_SPLITS . ", got $maxSplits.");
        }
        if (($arguments->float("split-penalty") ?? 0) < 0) {
            self::fail("The option --split-penalty must not be negative.");
        }

        try {
            // The reference loads after the checks of the inputs. Until then, an empty subtitle stands in for it.
            $this->syncOptions = new ReferenceSyncOptions(
                reference: new Subtitle(),
                minOffset: $arguments->float("min-offset") ?? -60,
                maxOffset: $arguments->float("max-offset") ?? 60,
                searchScale: !$arguments->has("no-scale"),
                maxSplits: (int)$maxSplits,
                splitPenalty: $arguments->float("split-penalty") ?? 0.1,
            );
        } catch (InvalidArgumentException $exception) {
            self::fail($exception->getMessage());
        }
    }


    protected function checkReference(Arguments $arguments): void
    {
        if ($arguments->has("reference") === $arguments->has("silence-log")) {
            self::fail("Pass one of --reference FILE and --silence-log FILE.");
        }
        if ($arguments->has("silence-log") !== $arguments->has("media-duration")) {
            self::fail("Pass --media-duration with --silence-log.");
        }
        $arguments->positiveFloat("media-duration");
    }


    protected function loadSideFiles(Arguments $arguments, Console $console): void
    {
        $log = $arguments->value("silence-log");
        if ($log === null) {
            $this->reference = $this->loadSideSubtitle($arguments->value("reference"), $console);

            return;
        }

        $content = self::readSideFile($log);

        try {
            $this->reference = SpeechReference::fromFfmpegSilencedetect($content, $arguments->positiveFloat("media-duration"));
        } catch (ParsingException $exception) {
            self::failSideFile($log, $exception->getMessage());
        }
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $options = $this->syncOptions;
        $result  = ReferenceSync::apply($subtitle, new ReferenceSyncOptions(
            $this->reference,
            $options->minOffset,
            $options->maxOffset,
            $options->searchScale,
            $options->maxSplits,
            $options->splitPenalty,
        ));

        $label = self::label($input);
        $text  = "$label: scale " . self::number($result->scale, 5) . ", offset " . self::number($result->offset, 3) .
                 " s, score " . self::number($result->score, 2) . "\n";
        $segments = $result->getSegments();
        if (count($segments) > 1) {
            foreach ($segments as $segment) {
                $text .= "$label: from " . self::number($segment["from"], 3) . " s: offset " . self::number($segment["offset"], 3) . " s\n";
            }
        }
        $console->err($text);
        if ($result->score < self::LOW_SCORE) {
            $console->err("$label: the score is below " . self::LOW_SCORE . ", so the files likely do not match.\n");
        }

        parent::process($input, $subtitle, $format, $arguments, $console);
    }


    private static function number(float $value, int $decimals): string
    {
        return rtrim(rtrim(number_format($value, $decimals, ".", ""), "0"), ".");
    }
}
