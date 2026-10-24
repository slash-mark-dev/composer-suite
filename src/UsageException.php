<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite;

/** Thrown for bad command-line usage; exits with code 2 and prints the command's help hint. */
final class UsageException extends \InvalidArgumentException
{
}
