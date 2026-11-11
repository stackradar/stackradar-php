<?php

declare(strict_types=1);

namespace StackRadar\Tests;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StackRadar\Client;
use StackRadar\HttpTransport;
use StackRadar\Inventory;
use StackRadar\PackageInventory;
use StackRadar\Transport;
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

    public function test_invalid_utf8_does_not_escape_the_report_exception_contract(): void
    {
        $context = self::CONTEXT;
        $context['deployment']['service'] = "bad\xB1utf8";
        try {
            (new Client(new HttpTransport('test-key', 'http://127.0.0.1:1')))->report($context, null);
            self::fail('Expected safe preparation failure.');
        } catch (TransportException $exception) {
            self::assertSame('report_failed', $exception->reason);
            self::assertStringNotContainsString('bad', $exception->getMessage());
        }
    }

    public function test_unexpected_transport_exceptions_are_wrapped_without_their_messages(): void
    {
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willThrowException(new InvalidArgumentException('Secret detail'));
        try {
            (new Client($transport))->report(self::CONTEXT, null);
            self::fail('Expected safe report failure.');
        } catch (TransportException $exception) {
            self::assertSame('report_failed', $exception->reason);
            self::assertStringNotContainsString('Secret', $exception->getMessage());
        }
    }

    public function test_reporter_version_is_unknown_if_the_sdk_is_not_registered_with_composer(): void
    {
        $original = InstalledVersions::getAllRawData()[0];
        $data = $original;
        $data['versions'] = [];
        $transport = new RecordingTransport;
        $client = new Client($transport);
        $loaders = ClassLoader::getRegisteredLoaders();
        try {
            foreach ($loaders as $loader) {
                $loader->unregister();
            }
            InstalledVersions::reload($data);
            $client->report(self::CONTEXT, null);
        } finally {
            foreach ($loaders as $loader) {
                $loader->register();
            }
            InstalledVersions::reload($original);
        }
        self::assertSame('unknown', $transport->calls[0]['payload']['reporter']['version']);
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
