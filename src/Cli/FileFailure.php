<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\SubtitleToolboxException;

/**
 * A file outside the inputs that the tool cannot read or write, such as the word list of --mask-words.
 *
 * @internal
 */
final class FileFailure extends \RuntimeException implements SubtitleToolboxException
{
}
