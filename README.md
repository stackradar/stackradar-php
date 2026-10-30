# StackRadar PHP SDK

Collect the Composer packages installed in an application and the PHP runtime that observes them. The SDK has no framework dependency and does nothing until `Client::report()` is called.

Requires PHP 8.2+, Composer runtime API 2.1+, and ext-curl. This is an unpublished first version; the endpoint and payload contract below are provisional until connected to the ingestion API.

## Usage

After the package is published:

```bash
composer require stackradar/php
```

```php
use StackRadar\Client;
use StackRadar\HttpTransport;

$client = new Client(
    environment: 'PROD',
    transport: new HttpTransport(
        endpoint: 'https://ingest.example.com/runtime',
        projectToken: getenv('STACKRADAR_PROJECT_TOKEN'),
    ),
);

$client->report([
    'instance_id' => 'app-1',
    'release' => 'deploy-123',
    'role' => 'web',
]);
```

Use `stackradar/laravel` for automatic Laravel reporting. The PHP SDK does not create timers, register shutdown handlers, or manage reporting state. The caller chooses the lifecycle and handles exceptions.

## Observation and wire format

Each report is a complete JSON snapshot:

```json
{
  "schema_version": 1,
  "observed_at": "2026-10-30T12:00:00Z",
  "environment": "PROD",
  "sdk": {"name": "stackradar/php", "version": "0.1.0"},
  "context": {"instance_id": "app-1", "release": "deploy-123", "role": "web"},
  "runtime": {
    "php_version": "8.3.0",
    "sapi": "fpm-fcgi",
    "extensions": [{"name": "curl", "version": "8.3.0"}]
  },
  "packages": [{
    "name": "vendor/package",
    "version": "1.2.0.0",
    "pretty_version": "v1.2.0",
    "reference": "commit-reference",
    "dev": false
  }]
}
```

The package collector reads `Composer\InstalledVersions`, includes installed development dependencies and metapackages, and excludes each dataset's root application and virtual/replaced entries without a concrete version. It preserves display versions, normalized versions, references and dev flags. Identical observations are deduplicated; conflicting observations from multiple autoloaders remain separate. Package and extension order is deterministic.

No source files, install paths, environment dumps, request data, hostname, or dependency graph are collected. The project token is sent only in the `Authorization: Bearer ...` header. Caller-supplied context is transmitted as provided; do not include secrets.

This is installed-package metadata observed from a running process, not evidence that every package was loaded or executed. Long-lived processes can retain old Composer metadata; restart workers on deployment. This SDK does not reconstruct the dependency graph from `composer.lock`.

## Delivery

- HTTP POST to the exact configured URL; no default API address is assumed.
- HTTPS required, except HTTP on loopback for local development.
- Total request timeout defaults to 2 seconds, configurable up to 10 seconds.
- Any 2xx response means accepted. Redirects are not followed.
- Network errors and other HTTP statuses throw `TransportException`; response bodies and credentials are excluded from exception messages.
- `TransportException::$retryAfter` contains parsed `Retry-After` seconds or an HTTP date, capped at seven days. The caller owns retry scheduling.
- Response bodies are discarded, and the SDK does not buffer failed reports or retry inline. A caller can collect a fresh snapshot on a later attempt; delivery is best effort and duplicates are possible.

Implement the small `StackRadar\Transport` interface to adapt authentication, JSON delivery, or testing to the eventual API. The SDK performs no HTTP client discovery and installs no other production PHP packages.

## Development

```bash
composer install
composer check
```

Tests make no external API calls. HTTP transport tests start a local PHP HTTP server and require loopback sockets and `proc_open`. Pint and PHPStan are development dependencies. The library lockfile is intentionally untracked. No remote repository or release has been created. License metadata remains proprietary pending a distribution decision.
