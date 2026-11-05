<?php

declare(strict_types=1);

namespace StackRadar;

use Composer\InstalledVersions;

class Inventory
{
    public function collect(): PackageInventory
    {
        $packages = array_map(static fn (array $package): array => [
            'name' => $package['name'],
            'version' => $package['pretty_version'] ?? $package['version'],
        ], ComposerPackages::collect(InstalledVersions::getAllRawData()));

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
}
