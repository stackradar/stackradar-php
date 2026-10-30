<?php

declare(strict_types=1);

namespace StackRadar;

use Composer\InstalledVersions;

class Inventory
{
    /** @return array<string, mixed> */
    public function collect(): array
    {
        $extensions = [];
        $names = get_loaded_extensions();
        sort($names, SORT_STRING);

        foreach ($names as $name) {
            $extensions[] = ['name' => $name, 'version' => phpversion($name) ?: null];
        }

        return [
            'runtime' => [
                'php_version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'extensions' => $extensions,
            ],
            'packages' => ComposerPackages::collect(InstalledVersions::getAllRawData()),
        ];
    }
}
