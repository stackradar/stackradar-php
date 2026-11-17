# stackradar/php

A Composer package that reads an application's installed Composer packages and PHP runtime versions and reports them to StackRadar. It has no framework dependency. [stackradar/laravel](https://github.com/stackradar/stackradar-laravel) builds on it.

## Layout

- `src/Client.php`: the public API, `collectInventory()` and `report()`.
- `src/Inventory.php`, `src/ComposerPackages.php`, `src/PackageInventory.php`: reading the installed packages and building the list.
- `src/Transport.php`, `src/HttpTransport.php`: the transport interface and its cURL implementation.
- `tests/`: PHPUnit tests. They use a recording transport and a local HTTP server, and never call StackRadar.

## Checks

Run `composer check` before committing. It runs Pint, PHPStan and PHPUnit. CI runs the same on PHP 8.2 to 8.5 with the lowest and highest dependencies, so code must work on PHP 8.2.

## Rules

- **The package runs inside other people's applications.** Reporting must never interrupt one. Failures surface as `TransportException` or `ConfigurationException`, with a reason code and no secrets in the message.
- **No new runtime dependencies.** The package requires PHP, the Composer runtime API and `ext-curl`.
- **Send only what the README says.** Package names and versions, and PHP, extension and OS versions. No source files, environment variables or install paths.
- **Never log or expose the Ingest Key**, including in exceptions and `var_export()` output.
- **`Client`, `Transport` and `HttpTransport` are a public API.** stackradar/laravel depends on them. A breaking change needs a new minor version while the package is `0.x`.
- A change in behaviour comes with a test. Tests use made-up data.

## Pull requests

- `main` changes only through a pull request.
- Each pull request gets one label: `semver:major`, `semver:minor`, `semver:patch` or `semver:chore`. The draft release is built from them.
- Maintainers create release tags with the Release workflow.
