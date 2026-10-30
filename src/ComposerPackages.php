<?php

declare(strict_types=1);

namespace StackRadar;

/** @internal */
final class ComposerPackages
{
    /**
     * @param  list<array<string, mixed>>  $datasets
     * @return list<array{name: string, version: ?string, pretty_version: ?string, reference: ?string, dev: bool}>
     */
    public static function collect(array $datasets): array
    {
        $packages = [];

        foreach ($datasets as $dataset) {
            $root = $dataset['root']['name'] ?? null;

            foreach ($dataset['versions'] ?? [] as $name => $info) {
                if ($name === $root || (! isset($info['version']) && ! isset($info['pretty_version']))) {
                    continue;
                }

                $package = [
                    'name' => $name,
                    'version' => $info['version'] ?? null,
                    'pretty_version' => $info['pretty_version'] ?? null,
                    'reference' => $info['reference'] ?? null,
                    'dev' => $info['dev_requirement'] ?? false,
                ];

                // Keep conflicting versions from different autoloaders instead of silently picking one.
                $packages[json_encode($package, JSON_THROW_ON_ERROR)] = $package;
            }
        }

        ksort($packages);

        return array_values($packages);
    }
}
