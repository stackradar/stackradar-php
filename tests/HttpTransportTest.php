<?php

declare(strict_types=1);

namespace StackRadar\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StackRadar\Client;
use StackRadar\HttpTransport;
use StackRadar\PackageInventory;
use StackRadar\TransportException;

final class HttpTransportTest extends TestCase
{
    private static mixed $server;

    private static string $endpoint;

    private static string $capture;

    private static string $log;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$endpoint = 'http://'.stream_socket_get_name($socket, false);
        fclose($socket);
        self::$capture = tempnam(sys_get_temp_dir(), 'stackradar-capture-');
        self::$log = tempnam(sys_get_temp_dir(), 'stackradar-server-');
        self::$server = proc_open([
            PHP_BINARY, '-S', substr(self::$endpoint, 7), __DIR__.'/fixtures/server.php',
        ], [0 => ['pipe', 'r'], 1 => ['file', self::$log, 'a'], 2 => ['file', self::$log, 'a']], $pipes,
            env_vars: [...getenv(), 'STACKRADAR_TEST_CAPTURE' => self::$capture]);
        fclose($pipes[0]);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $ready = @stream_socket_client(str_replace('http://', 'tcp://', self::$endpoint), $errno, $error, 0.05);
            if ($ready !== false) {
                fclose($ready);

                return;
            }
            usleep(20000);
        }

        throw new \RuntimeException('Local HTTP fixture did not start: '.file_get_contents(self::$log));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        unlink(self::$capture);
        unlink(self::$log);
    }

    protected function setUp(): void
    {
        file_put_contents(self::$capture, '');
    }

    public function test_it_posts_json_with_a_bearer_key_and_accepts_204(): void
    {
        (new HttpTransport('test-token', self::$endpoint))->send('POST', '/v1/accepted', ['environment' => 'PROD']);

        $request = json_decode(trim(file_get_contents(self::$capture)), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('POST', $request['method']);
        self::assertSame('Bearer test-token', $request['headers']['Authorization']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
        self::assertSame(['environment' => 'PROD'], $request['body']);
    }

    public function test_it_does_not_follow_redirects(): void
    {
        try {
            (new HttpTransport('test-token', self::$endpoint))->send('POST', '/v1/redirect', []);
            self::fail('A redirect must not count as an accepted report.');
        } catch (TransportException $exception) {
            self::assertSame(302, $exception->getCode());
            self::assertCount(1, file(self::$capture, FILE_IGNORE_NEW_LINES));
        }
    }

    public function test_it_exposes_retry_after_seconds(): void
    {
        try {
            (new HttpTransport('test-token', self::$endpoint))->send('POST', '/v1/rate-limit', []);
            self::fail('Expected an upload failure.');
        } catch (TransportException $exception) {
            self::assertSame(429, $exception->getCode());
            self::assertSame(120, $exception->retryAfter);
        }
    }

    public function test_it_exposes_retry_after_dates(): void
    {
        try {
            (new HttpTransport('test-token', self::$endpoint))->send('POST', '/v1/date-retry', []);
            self::fail('Expected an upload failure.');
        } catch (TransportException $exception) {
            self::assertGreaterThanOrEqual(118, $exception->retryAfter);
            self::assertLessThanOrEqual(120, $exception->retryAfter);
        }
    }

    public function test_it_bounds_network_time(): void
    {
        $started = microtime(true);

        try {
            (new HttpTransport('test-token', self::$endpoint))->send('POST', '/v1/slow', [], timeout: 0.05);
            self::fail('Expected a timeout.');
        } catch (TransportException $exception) {
            self::assertSame(0, $exception->getCode());
            self::assertLessThan(0.25, microtime(true) - $started);
        } finally {
            // The deliberately slow fixture is single-threaded; let it finish before the next test.
            usleep(350000);
        }
    }

    public function test_errors_do_not_expose_credentials_or_response_bodies(): void
    {
        try {
            (new HttpTransport('secret-token', self::$endpoint))->send('POST', '/v1/error', []);
            self::fail('Expected an upload failure.');
        } catch (TransportException $exception) {
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertStringNotContainsString('sensitive', $exception->getMessage());
        }
    }

    public function test_it_parses_the_unknown_inventory_code_from_the_bounded_json_response(): void
    {
        try {
            (new HttpTransport('test-token', self::$endpoint))->send('POST', '/v1/unknown', []);
            self::fail('Expected a conflict.');
        } catch (TransportException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertSame('unknown_inventory', $exception->reason);
        }
    }

    public function test_it_puts_plain_json_to_the_inventory_route(): void
    {
        $hash = str_repeat('a', 64);
        (new HttpTransport('test-token', self::$endpoint))->send('PUT', '/v1/package-inventories/'.$hash, ['protocol_version' => 1, 'ecosystem' => 'composer', 'packages' => []]);
        $request = json_decode(trim(file_get_contents(self::$capture)), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('PUT', $request['method']);
        self::assertSame('/v1/package-inventories/'.$hash, $request['uri']);
        self::assertArrayNotHasKey('Content-Encoding', $request['headers']);
    }

    public function test_the_real_transport_completes_the_handshake_then_sends_only_a_heartbeat(): void
    {
        $client = new Client(new HttpTransport('test-key', self::$endpoint));
        $inventory = new PackageInventory('composer', [['name' => 'vendor/package', 'version' => 'v1.2.3']]);
        $context = [
            'instance' => ['id' => '0192f8c4-7b1e-7c3a-9d2e-4f5a6b7c8d9e', 'started_at' => '2026-11-05T12:00:00Z'],
            'deployment' => ['environment' => null, 'service' => 'api', 'release' => null, 'artifact' => null],
        ];
        $client->report($context, $inventory);
        $client->report($context, $inventory);
        $requests = array_map(fn ($line) => json_decode($line, true), file(self::$capture, FILE_IGNORE_NEW_LINES));
        self::assertSame(['POST', 'PUT', 'POST'], array_column($requests, 'method'));
        self::assertSame('/v1/runtime-reports', $requests[0]['uri']);
        self::assertSame('/v1/package-inventories/'.$inventory->hash(), $requests[1]['uri']);
        self::assertSame($inventory->payload(), $requests[1]['body']);
        self::assertSame($inventory->hash(), $requests[2]['body']['packages']['inventory_hash']);
        self::assertSame('php', $requests[0]['body']['runtime']['name']);
        self::assertArrayNotHasKey('packages', $requests[0]['body']['packages']);
        self::assertArrayNotHasKey('project_token', $requests[0]['body']);
    }

    #[DataProvider('invalidOptions')]
    public function test_it_rejects_invalid_transport_options(string $endpoint, string $token): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HttpTransport($token, $endpoint);
    }

    public static function invalidOptions(): array
    {
        return [
            ['http://example.com', 'token'],
            ['file:///etc/passwd', 'token'],
            ['https://user:password@example.com', 'token'],
            ['https://example.com', "token\r\nInjected: header"],
            ['https://example.com', ''],
            ['https://example.com/v1/runtime-reports', 'token'],
            ['https://example.com?query=secret', 'token'],
        ];
    }
}
