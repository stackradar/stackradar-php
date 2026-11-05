<?php

declare(strict_types=1);

namespace StackRadar\Tests;

use PHPUnit\Framework\TestCase;
use StackRadar\Client;
use StackRadar\Inventory;
use StackRadar\PackageInventory;
use StackRadar\TransportException;

final class ClientTest extends TestCase
{
    private const CONTEXT = [
        'instance' => ['id' => '0192f8c4-7b1e-7c3a-9d2e-4f5a6b7c8d9e', 'started_at' => '2026-11-05T12:00:00Z'],
        'deployment' => ['environment' => 'PROD', 'service' => 'api', 'release' => null, 'artifact' => null],
    ];

    public function test_a_known_inventory_sends_only_a_check_in_without_collecting_packages(): void
    {
        $collector = $this->createMock(Inventory::class);
        $collector->expects(self::never())->method('collect');
        $collector->method('runtime')->willReturn(['name' => 'php', 'versions' => ['php' => PHP_VERSION]]);
        $transport = new RecordingTransport;
        $inventory = new PackageInventory('composer', [['name' => 'vendor/package', 'version' => 'v1.2.3']]);
        (new Client($transport, $collector))->report(self::CONTEXT, $inventory);
        self::assertCount(1, $transport->calls);
        $call = $transport->calls[0];
        self::assertSame(['POST', '/v1/runtime-reports'], [$call['method'], $call['path']]);
        $body = $call['payload'];
        self::assertSame(1, $body['protocol_version']);
        self::assertSame(self::CONTEXT['instance'], $body['instance']);
        self::assertSame(self::CONTEXT['deployment'], $body['deployment']);
        self::assertSame('stackradar/php', $body['reporter']['name']);
        self::assertSame('composer', $body['packages']['ecosystem']);
        self::assertSame($inventory->hash(), $body['packages']['inventory_hash']);
        self::assertArrayNotHasKey('list', $body['packages']);
        self::assertArrayNotHasKey('packages', $body['packages']);
    }

    public function test_only_an_explicit_unknown_inventory_response_triggers_a_put(): void
    {
        $transport = new RecordingTransport;
        $transport->failures = [new TransportException('Unknown', 409, reason: 'unknown_inventory')];
        $inventory = new PackageInventory('composer', [['name' => 'vendor/package', 'version' => 'dev-main']]);
        (new Client($transport))->report(self::CONTEXT, $inventory);
        self::assertCount(2, $transport->calls);
        self::assertSame('PUT', $transport->calls[1]['method']);
        self::assertSame('/v1/package-inventories/'.$inventory->hash(), $transport->calls[1]['path']);
        self::assertSame($inventory->payload(), $transport->calls[1]['payload']);
        self::assertLessThanOrEqual($transport->calls[0]['timeout'], $transport->calls[1]['timeout']);
    }

    public function test_a_different_conflict_does_not_trigger_an_inventory_upload(): void
    {
        $transport = new RecordingTransport;
        $transport->failures = [new TransportException('Conflict', 409, reason: 'another_conflict')];
        try {
            (new Client($transport))->report(self::CONTEXT, new PackageInventory('composer', []));
            self::fail('Expected a conflict.');
        } catch (TransportException) {
            self::assertCount(1, $transport->calls);
        }
    }

    public function test_it_checks_in_without_a_list_after_collection_failure(): void
    {
        $transport = new RecordingTransport;
        (new Client($transport))->report(self::CONTEXT, null, [['code' => 'inventory_too_large']]);
        $packages = $transport->calls[0]['payload']['packages'];
        self::assertSame('failed', $packages['status']);
        self::assertArrayNotHasKey('inventory_hash', $packages);
        self::assertArrayNotHasKey('ecosystem', $packages);
        self::assertSame([['code' => 'inventory_too_large']], $packages['errors']);
    }

    public function test_inventory_rejections_are_distinguished_from_check_in_rejections(): void
    {
        $transport = new RecordingTransport;
        $transport->failures = [
            new TransportException('Unknown', 409, reason: 'unknown_inventory'),
            new TransportException('Invalid inventory', 422, reason: 'inventory_hash_mismatch'),
        ];
        try {
            (new Client($transport))->report(self::CONTEXT, new PackageInventory('composer', []));
            self::fail('Expected rejection.');
        } catch (TransportException $exception) {
            self::assertTrue($exception->inventoryUpload);
            self::assertSame('inventory_hash_mismatch', $exception->reason);
        }
    }

    public function test_an_exhausted_budget_does_not_start_a_second_network_request(): void
    {
        $transport = new RecordingTransport;
        $transport->failures = [new TransportException('Unknown', 409, reason: 'unknown_inventory')];
        $transport->onSend = static fn () => usleep(20000);
        try {
            (new Client($transport, timeout: 0.01))->report(self::CONTEXT, new PackageInventory('composer', []));
            self::fail('Expected time budget exhaustion.');
        } catch (TransportException $exception) {
            self::assertSame(0, $exception->getCode());
            self::assertCount(1, $transport->calls);
        }
    }
}
