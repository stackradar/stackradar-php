<?php

declare(strict_types=1);

namespace StackRadar;

interface Transport
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws TransportException when the report is not accepted
     */
    public function send(string $method, string $path, array $payload, float $timeout = 2.0): void;
}
