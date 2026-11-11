# StackRadar PHP SDK

Collect installed Composer packages and report PHP runtime observations using StackRadar Runtime Ingest Protocol v1. Requires PHP 8.2+, Composer runtime API 2.1+, and ext-curl. The SDK is inert until explicitly called; use the Laravel integration for lifecycle reporting.

## Server compatibility

Runtime Ingest v1 accepts PHP runtime reports and Composer inventories. The SDK sends `runtime.name: php`, `runtime.versions.php`, and `packages.ecosystem: composer`. Composer display versions are preserved so deployed packages use the same identity as repository lockfile packages.

Compatibility was checked against the PHP/Composer server implementation merged in v0 PR #784 (`4eb7d27`). Generated reports for installed packages, empty inventories, conflicting package versions and collection failures pass the server validator. Inventory hashes match the server implementation, and its package identity retains versions such as `v11.31.0` unchanged. Local HTTP tests cover the POST → 409 → PUT → 204 flow and subsequent check-ins. These checks do not contact the production endpoint.

A valid Project Ingest Key and runtime reporting enabled for the organization are still required; SDK compatibility does not bypass authentication or the feature toggle. Deployed snapshots remain separate from repository exposure state.

The packages remain unpublished. The previous prototype's single full-snapshot POST and `project_token`/`endpoint` configuration have been replaced by the landed protocol.

## Explicit PHP usage

After publishing, install `stackradar/php`. Configure an Ingest Key issued for the Project; no project ID is sent.

```php
use StackRadar\Client;
use StackRadar\HttpTransport;

$client = new Client(new HttpTransport(
    ingestKey: getenv('STACKRADAR_INGEST_KEY'),
));
$inventory = $client->collectInventory();

$context = [
    'instance' => [
        'id' => '0192f8c4-7b1e-7c3a-9d2e-4f5a6b7c8d9e', // Your persisted reporter UUID.
        'started_at' => '2026-11-05T12:00:00Z',
    ],
    'deployment' => [
        'environment' => 'PROD', // null is accepted by the protocol.
        'service' => 'api',
        'release' => ['value' => 'deploy-123', 'source' => 'config'],
        'artifact' => null,
    ],
];
$client->report($context, $inventory);
```

Reuse the inventory and instance identity for later check-ins. Call `collectInventory()` again when the installation changes. The generic SDK does not create timers, caches, shutdown hooks, or disk queues; callers own scheduling and response policy. Pass `null` instead of an inventory, with an optional list of `['code' => '...']` errors, to report collection failure without a partial list.

## Wire behavior

1. POST plain JSON to `https://ingest.stackradar.com/v1/runtime-reports` with `Authorization: Bearer <ingest-key>`.
2. The body contains `protocol_version: 1`, `reporter`, `instance`, `deployment`, `runtime`, `platform`, and `packages` metadata. A collected inventory names `packages.ecosystem: composer` and its 64-character lowercase `inventory_hash`; it never includes the list.
3. HTTP 204 finishes the operation. Only HTTP 409 with `code: unknown_inventory` requests an upload.
4. PUT `/v1/package-inventories/{hash}` with `protocol_version: 1`, `ecosystem`, and `packages: [{name, version}]`. HTTP 204 finishes; there is no extra POST after the upload.

Later check-ins repeat the same POST, so an unknown inventory can be uploaded again if the server loses it. There is no gzip, inline retry, per-package request, or local outbox. Only HTTP 204 counts as success. Redirects are never followed. The shared POST/PUT network budget defaults to 2 seconds and can be configured up to 10 seconds through `Client`.

`TransportException` exposes the HTTP status through `getCode()`, parsed `Retry-After` seconds (capped at seven days), the bounded machine-readable `reason`, and whether the failure occurred during `inventoryUpload`. Exception messages omit credentials, URLs and response bodies. The transport reads at most 16 KiB of a response to extract its error code, discarding the remainder.

`HttpTransport` accepts an optional origin override for tests. HTTPS is required except HTTP on loopback. Implement `StackRadar\Transport::send($method, $path, $payload, $timeout)` to replace delivery; custom transports must obey the remaining timeout supplied by the client.

## Inventory and runtime data

The collector reads `Composer\InstalledVersions` without running Composer or reading lockfile contents. It excludes root applications and virtual/provided entries without concrete versions, includes installed dev dependencies and metapackages, and preserves distinct name/version pairs across autoloaders. The wire version is Composer's display version, falling back to its normalized version. Hashing does not normalize either string.

The v1 API carries only name/version pairs. References and dev flags are omitted, and different commits of the same `dev-main` version cannot be distinguished by this protocol. No synthetic commit suffix is added to versions. Installed metadata establishes presence, not execution, reachability, or file integrity.

The hash is SHA-256 over these exact bytes:

```text
stackradar-inventory-v1\n
{ecosystem}\n
{name}\t{version}\n
...
```

In the example, `\n` and `\t` denote newline and tab bytes. Each name/version line is unique and sorted by byte order. There is a final newline after every package. No case folding, whitespace trimming, or JSON serialization participates in the hash. Tests pin the landed server's reference vector.

Lists exceeding 10,000 entries or a 1 MiB serialized request are rejected locally, never truncated. Package fields must be 1–255 Unicode characters without control characters. Empty complete inventories are valid.

Runtime metadata contains `name: php`, `versions.php`, up to 199 loaded extension version entries named `ext-<name>`, and SAPI. Platform metadata includes OS family, architecture and OS release. No hostnames, absolute paths, file contents, environment dumps, process arguments or dependency graph are sent. Caller-supplied context must not contain secrets.

## Development

```bash
composer install
composer check
```

Tests use fake transports and a local HTTP fixture, with no external API calls. The HTTP tests need loopback sockets and `proc_open`. The HTTP fixture verifies delivery behavior; server contract validation is described above. The library lockfile is intentionally untracked. License metadata remains proprietary pending a distribution decision.
