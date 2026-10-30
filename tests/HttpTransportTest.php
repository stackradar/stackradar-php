<?php

declare(strict_types=1);

namespace StackRadar\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StackRadar\HttpTransport;
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

    public function test_it_posts_json_with_a_bearer_token_and_accepts_202(): void
    {
        (new HttpTransport(self::$endpoint.'/accepted', 'test-token'))->send(['environment' => 'PROD']);

        $request = json_decode(trim(file_get_contents(self::$capture)), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('POST', $request['method']);
        self::assertSame('Bearer test-token', $request['headers']['Authorization']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
        self::assertSame(['environment' => 'PROD'], $request['body']);
    }

    public function test_it_does_not_follow_redirects(): void
    {
        try {
            (new HttpTransport(self::$endpoint.'/redirect', 'test-token'))->send([]);
            self::fail('A redirect must not count as an accepted report.');
        } catch (TransportException $exception) {
            self::assertSame(302, $exception->getCode());
            self::assertCount(1, file(self::$capture, FILE_IGNORE_NEW_LINES));
        }
    }

    public function test_it_exposes_retry_after_seconds(): void
    {
        try {
            (new HttpTransport(self::$endpoint.'/rate-limit', 'test-token'))->send([]);
            self::fail('Expected an upload failure.');
        } catch (TransportException $exception) {
            self::assertSame(429, $exception->getCode());
            self::assertSame(120, $exception->retryAfter);
        }
    }

    public function test_it_exposes_retry_after_dates(): void
    {
        try {
            (new HttpTransport(self::$endpoint.'/date-retry', 'test-token'))->send([]);
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
            (new HttpTransport(self::$endpoint.'/slow', 'test-token', timeout: 0.05))->send([]);
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
            (new HttpTransport(self::$endpoint.'/error?private-query=secret', 'secret-token'))->send([]);
            self::fail('Expected an upload failure.');
        } catch (TransportException $exception) {
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertStringNotContainsString('sensitive', $exception->getMessage());
        }
    }

    #[DataProvider('invalidOptions')]
    public function test_it_rejects_invalid_transport_options(string $endpoint, string $token, float $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HttpTransport($endpoint, $token, $timeout);
    }

    public static function invalidOptions(): array
    {
        return [
            ['http://example.com/ingest', 'token', 2.0],
            ['file:///etc/passwd', 'token', 2.0],
            ['https://user:password@example.com', 'token', 2.0],
            ['https://example.com', "token\r\nInjected: header", 2.0],
            ['https://example.com', '', 2.0],
            ['https://example.com', 'token', 0.0],
            ['https://example.com', 'token', 11.0],
        ];
    }
}
