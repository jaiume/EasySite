<?php

declare(strict_types=1);

namespace App\Support;

final class OpenRouterException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $details,
        int $code = 0,
        private readonly bool $timeout = false,
    ) {
        parent::__construct($message, $code);
    }

    public function details(): string
    {
        return $this->details;
    }

    public function isTimeout(): bool
    {
        return $this->timeout;
    }
}
