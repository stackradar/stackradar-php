<?php

declare(strict_types=1);

namespace StackRadar;

use RuntimeException;

final class TransportException extends RuntimeException
{
    public function __construct(string $message, int $status = 0, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message, $status);
    }
}
