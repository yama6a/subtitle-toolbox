<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\SubtitleToolboxException;

/**
 * A file outside the inputs that the tool cannot read, such as the word list of --mask-words.
 * An output file that the tool cannot create is one too. It stops the run, also with --keep-going.
 *
 * @internal
 */
final class FileFailure extends \RuntimeException implements SubtitleToolboxException
{
}
