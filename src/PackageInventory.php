<?php

declare(strict_types=1);

namespace StackRadar;

use InvalidArgumentException;
use LengthException;

final class PackageInventory
{
    /** @var list<array{name: string, version: string}> */
    public readonly array $packages;

    /** @param list<array{name: string, version: string}> $packages */
    public function __construct(public readonly string $ecosystem, array $packages)
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]*\z/', $ecosystem) !== 1) {
            throw new InvalidArgumentException('Invalid inventory ecosystem.');
        }
        $canonical = [];
        foreach ($packages as $package) {
            foreach (['name', 'version'] as $field) {
                if (preg_match('/\A[^\p{Cc}]{1,255}\z/u', $package[$field]) !== 1) {
                    throw new InvalidArgumentException('Package fields must contain 1–255 characters without controls.');
                }
            }
            $canonical[$package['name']."\t".$package['version']] = ['name' => $package['name'], 'version' => $package['version']];
        }
        ksort($canonical, SORT_STRING);
        if (count($canonical) > 10000) {
            throw new LengthException('The inventory exceeds 10,000 packages.');
        }
        $this->packages = array_values($canonical);

        if (strlen(json_encode($this->payload(), JSON_THROW_ON_ERROR)) > 1048576) {
            throw new LengthException('The inventory exceeds 1 MiB.');
        }
    }

    public function hash(): string
    {
        $body = "stackradar-inventory-v1\n".$this->ecosystem."\n";
        foreach ($this->packages as $package) {
            $body .= $package['name']."\t".$package['version']."\n";
        }

        return hash('sha256', $body);
    }

    /** @return array{protocol_version: int, ecosystem: string, packages: list<array{name: string, version: string}>} */
    public function payload(): array
    {
        return ['protocol_version' => 1, 'ecosystem' => $this->ecosystem, 'packages' => $this->packages];
    }
}
