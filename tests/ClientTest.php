<?php

declare(strict_types=1);

namespace StackRadar\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StackRadar\Client;
use StackRadar\Inventory;
use StackRadar\Transport;

final class ClientTest extends TestCase
{
    public function test_collecting_and_sending_happens_only_when_explicitly_requested(): void
    {
        $inventory = $this->createMock(Inventory::class);
        $inventory->expects(self::once())->method('collect')->willReturn(['runtime' => ['sapi' => 'cli'], 'packages' => []]);
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::once())->method('send')->with(self::callback(function (array $payload): bool {
            self::assertSame(1, $payload['schema_version']);
            self::assertSame('PROD', $payload['environment']);
            self::assertSame('stackradar/php', $payload['sdk']['name']);
            self::assertSame('release-a', $payload['context']->release);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['observed_at']);

            return true;
        }));

        $client = new Client('PROD', $transport, $inventory);
        $client->report(['release' => 'release-a']);
    }

    public function test_an_environment_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client(' ', $this->createMock(Transport::class));
    }
}
