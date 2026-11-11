<?php

declare(strict_types=1);

namespace StackRadar;

use InvalidArgumentException;

final class ConfigurationException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
