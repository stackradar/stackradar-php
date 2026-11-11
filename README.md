# StackRadar for PHP

Report installed Composer packages and PHP runtime versions to StackRadar. For automatic reporting in Laravel, use [stackradar/laravel](https://github.com/stackradar/laravel-sdk).

Requires PHP 8.2+, Composer 2.1+, and cURL.

## Install

```bash
composer require stackradar/php
```

## Usage

Set `STACKRADAR_INGEST_KEY` to your Project's Ingest Key. Supply a UUID and an ISO 8601 start time as `$instanceId` and `$startedAt`; reuse them for subsequent check-ins.

```php
use StackRadar\Client;
use StackRadar\HttpTransport;

try {
    $client = new Client(new HttpTransport(getenv('STACKRADAR_INGEST_KEY')));
    $inventory = $client->collectInventory();

    $client->report([
        'instance' => ['id' => $instanceId, 'started_at' => $startedAt],
        'deployment' => ['environment' => 'production', 'service' => 'api'],
    ], $inventory);
} catch (\Throwable) {
    // Reporting must not interrupt the application.
}
```

The SDK sends package names and installed versions, plus PHP, extension and OS versions. It sends no source files, environment dumps or install paths.

The caller controls when to report. Reuse the inventory between check-ins and create a new client after the installation changes. The Laravel integration handles scheduling and identity for you.

## Development

```bash
composer install
composer check
```

Tests use fake transports and a local HTTP server; they don't call StackRadar.

## License

[Apache 2.0](LICENSE).
