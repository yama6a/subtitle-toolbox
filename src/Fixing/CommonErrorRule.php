<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

/**
 * The fixes in the order they run. The value of a case is the name of its field in CommonErrorOptions.
 */
enum CommonErrorRule: string
{
    case ReplaceList                  = "replaceList";
    case UnbalancedTags               = "unbalancedTags";
    case EmptyTags                    = "emptyTags";
    case OcrPipe                      = "ocrPipe";
    case OcrZeroInWords               = "ocrZeroInWords";
    case OcrLowercaseL                = "ocrLowercaseL";
    case LoneLowercaseI               = "loneLowercaseI";
    case Ellipsis                     = "ellipsis";
    case DoubleSpaces                 = "doubleSpaces";
    case SpaceBeforePunctuation       = "spaceBeforePunctuation";
    case MissingSpaceAfterPunctuation = "missingSpaceAfterPunctuation";
    case DialogueOnOneLine            = "dialogueOnOneLine";
    case DialogueDashes               = "dialogueDashes";
    case SentenceStartCase            = "sentenceStartCase";
}
