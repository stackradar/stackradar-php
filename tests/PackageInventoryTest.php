<?php

declare(strict_types=1);

namespace StackRadar\Tests;

use InvalidArgumentException;
use LengthException;
use PHPUnit\Framework\TestCase;
use StackRadar\PackageInventory;

final class PackageInventoryTest extends TestCase
{
    public function test_it_matches_the_landed_server_reference_vector(): void
    {
        // v0 a69ddbb, PackageInventoryHashTest::test_matches_the_protocol_v1_reference_vector.
        $inventory = new PackageInventory('npm', [
            ['name' => 'lodash', 'version' => '4.17.21'],
            ['name' => '@types/node', 'version' => '22.9.0'],
        ]);
        self::assertSame('7ccf9726ffe6d03b3560f8d9b39c627ae11e7e2014adc0368c5fd678e0b2640c', $inventory->hash());
        self::assertSame('@types/node', $inventory->packages[0]['name']);
        self::assertSame($inventory->hash(), (new PackageInventory('npm', [...array_reverse($inventory->packages), $inventory->packages[0]]))->hash());
        self::assertNotSame($inventory->hash(), (new PackageInventory('composer', $inventory->packages))->hash());
    }

    public function test_it_preserves_different_versions_and_does_not_normalize_case_or_whitespace(): void
    {
        $packages = [['name' => 'vendor/a', 'version' => 'v1.0.0'], ['name' => 'vendor/a', 'version' => 'v2.0.0']];
        $inventory = new PackageInventory('composer', $packages);
        self::assertCount(2, $inventory->packages);
        self::assertNotSame($inventory->hash(), (new PackageInventory('composer', [['name' => 'Vendor/a', 'version' => 'v1.0.0'], $packages[1]]))->hash());
        self::assertNotSame($inventory->hash(), (new PackageInventory('composer', [['name' => 'vendor/a ', 'version' => 'v1.0.0'], $packages[1]]))->hash());
    }

    public function test_control_characters_are_rejected_before_hashing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PackageInventory('composer', [['name' => "vendor/a\tbad", 'version' => '1.0']]);
    }

    public function test_an_inventory_is_never_truncated_to_fit_the_package_limit(): void
    {
        $this->expectException(LengthException::class);
        new PackageInventory('composer', array_map(fn ($i) => ['name' => 'vendor/a', 'version' => '1.0.'.$i], range(0, 10000)));
    }

    public function test_the_serialized_size_limit_is_checked_as_well_as_package_count(): void
    {
        $packages = [];
        for ($i = 0; $i < 3000; $i++) {
            $packages[] = ['name' => str_repeat('a', 245).$i, 'version' => str_repeat('1', 250)];
        }
        $this->expectException(LengthException::class);
        new PackageInventory('composer', $packages);
    }
}
