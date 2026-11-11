<?php

declare(strict_types=1);

namespace StackRadar\Tests;

use Closure;
use StackRadar\Transport;
use StackRadar\TransportException;

final class RecordingTransport implements Transport
{
    public array $calls = [];

    public array $failures = [];

    public ?Closure $onSend = null;

    public function send(string $method, string $path, array $payload, float $timeout = 2.0): void
    {
        $this->calls[] = compact('method', 'path', 'payload', 'timeout');
        ($this->onSend ?? static fn () => null)();
        $failure = array_shift($this->failures);
        if ($failure instanceof TransportException) {
            throw $failure;
        }
    }
}
