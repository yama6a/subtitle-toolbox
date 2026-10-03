<?php

namespace SubtitleToolbox;

/**
 * A subtitle, transcript or chapter format. The value is the format name of the command line tool.
 */
enum Format: string
{
    case Ass               = "ass";
    case Csv               = "csv";
    case FfMetadata        = "ffmeta";
    case HtmlTranscript    = "html";
    case Itt               = "itt";
    case Json              = "json";
    case AssemblyAi        = "assemblyai";
    case AwsTranscribe     = "aws-transcribe";
    case Deepgram          = "deepgram";
    case GoogleSpeech      = "google-speech";
    case Lyrics            = "lrc";
    case MicroDvd          = "microdvd";
    case MpSub             = "mpsub";
    case Pgs               = "pgs";
    case Sami              = "sami";
    case Sbv               = "sbv";
    case Scc               = "scc";
    case SubRip            = "srt";
    case EbuStl            = "stl";
    case SubViewer         = "subviewer";
    case Ttml              = "ttml";
    case Tsv               = "tsv";
    case PlainText         = "txt";
    case Mpl2              = "mpl2";
    case TmPlayer          = "tmplayer";
    case VobSub            = "vobsub";
    case WebVtt            = "vtt";
    case Whisper           = "whisper";
    case YouTube           = "youtube";
    case OgmChapters       = "ogm";
    case PodcastChapters   = "podcast";
    case PodcastTranscript = "podcast-transcript";
    case YouTubeChapters   = "ytchapter";


    /**
     * Returns the format of the file extension of $path, or null for an unknown or missing extension.
     * When two formats share an extension, the earlier case owns it, so `.sub` is MicroDVD and `.txt` is plain text.
     */
    public static function fromPath(string $path): ?self
    {
        return FormatRegistry::forPath($path);
    }


    /**
     * Returns the format whose signature matches the start of $content, or null. It never returns a format whose
     * isAutoDetected() is false.
     */
    public static function detect(string $content): ?self
    {
        $format = FormatDetector::detect($content);

        return $format?->isAutoDetected() ? $format : null;
    }


    /**
     * Returns the file extensions without the dot. The first one is the extension for new files.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return FormatRegistry::extensions($this);
    }


    public function canRead(): bool
    {
        return FormatRegistry::parserClass($this) !== null;
    }


    public function canWrite(): bool
    {
        return FormatRegistry::formatterClass($this) !== null;
    }


    /**
     * Returns false for chapters and cloud speech JSON. Their content looks like other formats, so they load only
     * when the caller names them.
     */
    public function isAutoDetected(): bool
    {
        return match ($this) {
            self::YouTubeChapters, self::PodcastChapters, self::FfMetadata, self::OgmChapters,
            self::AwsTranscribe, self::Deepgram, self::AssemblyAi, self::GoogleSpeech => false,
            default => true,
        };
    }
}
