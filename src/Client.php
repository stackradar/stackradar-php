<?php

declare(strict_types=1);

namespace StackRadar;

use Composer\InstalledVersions;
use OutOfBoundsException;
use Throwable;

final class Client
{
    public function __construct(
        private readonly Transport $transport,
        private readonly Inventory $inventory = new Inventory,
        private readonly float $timeout = 2.0,
        private readonly string $reporterName = 'stackradar/php',
    ) {
        if (! is_finite($timeout) || $timeout <= 0 || $timeout > 10) {
            throw new ConfigurationException('The reporting timeout must be greater than zero and at most 10 seconds.', 'invalid_timeout');
        }
    }

    public function collectInventory(): PackageInventory
    {
        return $this->inventory->collect();
    }

    public function inventoryFingerprint(): string
    {
        return $this->inventory->fingerprint();
    }

    /**
     * @param  array{instance: array{id: string, started_at: string}, deployment: array<string, mixed>}  $context
     * @param  list<array{code: string}>  $errors
     *
     * @throws TransportException when preparation or delivery fails
     */
    public function report(array $context, ?PackageInventory $packages, array $errors = []): void
    {
        try {
            $this->sendReport($context, $packages, $errors);
        } catch (TransportException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new TransportException('Unable to prepare or send the StackRadar report.', reason: 'report_failed');
        }
    }

    /**
     * @param  array{instance: array{id: string, started_at: string}, deployment: array<string, mixed>}  $context
     * @param  list<array{code: string}>  $errors
     */
    private function sendReport(array $context, ?PackageInventory $packages, array $errors): void
    {
        $deadline = microtime(true) + $this->timeout;
        $payload = [
            'protocol_version' => 1,
            'reporter' => [
                'name' => $this->reporterName,
                'version' => $this->reporterVersion(),
            ],
            'instance' => $context['instance'],
            'deployment' => $context['deployment'],
            'runtime' => $this->inventory->runtime(),
            'platform' => [
                'os' => strtolower(PHP_OS_FAMILY),
                'arch' => php_uname('m'),
                'os_release' => php_uname('r'),
            ],
            'packages' => $packages === null ? [
                'detector' => 'composer-installed-versions',
                'status' => 'failed',
                'errors' => $errors ?: [['code' => 'collection_failed']],
            ] : [
                'detector' => 'composer-installed-versions',
                'status' => 'collected',
                'ecosystem' => $packages->ecosystem,
                'inventory_hash' => $packages->hash(),
            ],
        ];

        try {
            $this->transport->send('POST', '/v1/runtime-reports', $payload, $this->timeout);

            return;
        } catch (TransportException $exception) {
            if ($exception->getCode() !== 409 || $exception->reason !== 'unknown_inventory' || $packages === null) {
                throw $exception;
            }
        }

        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new TransportException('The StackRadar reporting time budget was exhausted.');
        }

        try {
            $this->transport->send('PUT', '/v1/package-inventories/'.$packages->hash(), $packages->payload(), $remaining);
        } catch (TransportException $exception) {
            throw new TransportException(
                $exception->getMessage(), $exception->getCode(), $exception->retryAfter,
                $exception->reason, inventoryUpload: true,
            );
        }
    }

    private function reporterVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion($this->reporterName) ?? 'unknown';
        } catch (OutOfBoundsException) {
            return 'unknown';
        }
    }
}
