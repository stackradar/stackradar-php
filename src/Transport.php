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
    public function send(array $payload): void;
}
