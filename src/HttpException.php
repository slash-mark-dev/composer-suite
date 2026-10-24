<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite;

final class HttpException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $url = '')
    {
        parent::__construct($message);
    }
}
