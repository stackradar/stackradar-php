<?php

declare(strict_types=1);

namespace StackRadar;

use Composer\InstalledVersions;

class Inventory
{
    /** @var list<array<string, mixed>>|null */
    private ?array $loadedPackages = null;

    private ?string $fingerprint = null;

    public function fingerprint(): string
    {
        return $this->fingerprint ??= hash('sha256', json_encode($this->loadedPackages(), JSON_THROW_ON_ERROR));
    }

    public function collect(): PackageInventory
    {
        $packages = array_map(static fn (array $package): array => [
            'name' => $package['name'],
            'version' => $package['pretty_version'] ?? $package['version'],
        ], ComposerPackages::collect($this->loadedPackages()));

        return new PackageInventory('composer', $packages);
    }

    /** @return array<string, mixed> */
    public function runtime(): array
    {
        $names = get_loaded_extensions();
        sort($names, SORT_STRING);
        $versions = ['php' => PHP_VERSION];
        foreach (array_slice($names, 0, 199) as $name) {
            $versions['ext-'.$name] = phpversion($name) ?: 'unknown';
        }

        return ['name' => 'php', 'versions' => $versions, 'sapi' => PHP_SAPI];
    }

    /** @return list<array<string, mixed>> */
    private function loadedPackages(): array
    {
        return $this->loadedPackages ??= InstalledVersions::getAllRawData();
    }
}
