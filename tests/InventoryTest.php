<?php

declare(strict_types=1);

namespace StackRadar\Tests;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use StackRadar\ComposerPackages;
use StackRadar\Inventory;

final class InventoryTest extends TestCase
{
    public function test_it_reports_concrete_packages_without_collapsing_conflicting_versions(): void
    {
        $first = [
            'root' => ['name' => 'private/application'],
            'versions' => [
                'private/application' => ['version' => 'dev-main'],
                'virtual/logger' => ['provided' => ['1.0']],
                'replaced/package' => ['replaced' => ['*']],
                'vendor/package' => [
                    'version' => '1.2.0.0', 'pretty_version' => 'v1.2.0',
                    'reference' => 'commit-a', 'install_path' => '/private/path',
                    'dev_requirement' => false,
                ],
                'vendor/dev-tool' => ['version' => '2.0.0.0', 'dev_requirement' => true],
                'vendor/metapackage' => ['version' => '1.0.0.0', 'install_path' => null],
            ],
        ];
        $second = [
            'root' => ['name' => 'another/application'],
            'versions' => [
                'another/application' => ['version' => 'dev-main'],
                'vendor/package' => ['version' => '3.0.0.0', 'reference' => 'commit-b'],
            ],
        ];

        $packages = ComposerPackages::collect([$first, $first, $second]);

        self::assertCount(4, $packages);
        self::assertSame($packages, ComposerPackages::collect([$second, $first]));
        self::assertSame(['vendor/dev-tool', 'vendor/metapackage', 'vendor/package', 'vendor/package'], array_column($packages, 'name'));
        self::assertTrue($packages[0]['dev']);
        self::assertSame('v1.2.0', $packages[2]['pretty_version']);
        self::assertSame('commit-a', $packages[2]['reference']);
        self::assertStringNotContainsString('/private/path', json_encode($packages, JSON_THROW_ON_ERROR));
    }

    public function test_the_loaded_metadata_fingerprint_and_collection_remain_consistent(): void
    {
        $original = InstalledVersions::getAllRawData()[0];
        $data = $original;
        $data['versions'] = ['vendor/package' => ['version' => '1.0.0.0', 'pretty_version' => '1.0.0']];
        try {
            InstalledVersions::reload($data);
            $old = new Inventory;
            $fingerprint = $old->fingerprint();
            $data['versions']['vendor/package']['pretty_version'] = '2.0.0';
            InstalledVersions::reload($data);
            self::assertSame($fingerprint, $old->fingerprint());
            self::assertSame('1.0.0', array_column($old->collect()->packages, 'version', 'name')['vendor/package']);
            $fresh = new Inventory;
            self::assertNotSame($fingerprint, $fresh->fingerprint());
            self::assertSame('2.0.0', array_column($fresh->collect()->packages, 'version', 'name')['vendor/package']);
        } finally {
            InstalledVersions::reload($original);
        }
    }

    public function test_it_observes_the_current_runtime_and_installed_packages(): void
    {
        $collector = new Inventory;
        $runtime = $collector->runtime();
        self::assertSame('php', $runtime['name']);
        self::assertSame(PHP_VERSION, $runtime['versions']['php']);
        self::assertSame(PHP_SAPI, $runtime['sapi']);
        self::assertSame(phpversion('curl'), $runtime['versions']['ext-curl']);
        $inventory = $collector->collect();
        self::assertSame('composer', $inventory->ecosystem);
        $packages = array_column($inventory->packages, null, 'name');
        self::assertArrayHasKey('phpunit/phpunit', $packages);
        self::assertArrayNotHasKey('stackradar/php', $packages);
        self::assertSame(['name', 'version'], array_keys($packages['phpunit/phpunit']));
    }
}
