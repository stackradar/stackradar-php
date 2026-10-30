<?php

declare(strict_types=1);

namespace StackRadar;

use Composer\InstalledVersions;
use InvalidArgumentException;

final class Client
{
    public function __construct(
        private readonly string $environment,
        private readonly Transport $transport,
        private readonly Inventory $inventory = new Inventory,
    ) {
        if (trim($environment) === '') {
            throw new InvalidArgumentException('A StackRadar environment is required.');
        }
    }

    /** @param array<string, mixed> $context */
    public function report(array $context = []): void
    {
        $this->transport->send([
            'schema_version' => 1,
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'environment' => $this->environment,
            'sdk' => [
                'name' => 'stackradar/php',
                'version' => InstalledVersions::getPrettyVersion('stackradar/php'),
            ],
            'context' => (object) $context,
            ...$this->inventory->collect(),
        ]);
    }
}
