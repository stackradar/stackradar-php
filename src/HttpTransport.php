<?php

declare(strict_types=1);

namespace StackRadar;

use InvalidArgumentException;
use SensitiveParameter;

final class HttpTransport implements Transport
{
    public function __construct(
        #[SensitiveParameter] private readonly string $ingestKey,
        private readonly string $baseUrl = 'https://ingest.stackradar.com',
    ) {
        $url = parse_url($baseUrl);
        $local = in_array($url['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);
        if (! $url || empty($url['host']) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || ! in_array($url['path'] ?? '', ['', '/'], true)
            || (($url['scheme'] ?? '') !== 'https' && ! (($url['scheme'] ?? '') === 'http' && $local))) {
            throw new InvalidArgumentException('The ingest URL must be an HTTPS origin (HTTP is allowed on loopback).');
        }
        if (trim($ingestKey) === '' || strpbrk($ingestKey, "\r\n") !== false) {
            throw new InvalidArgumentException('A valid StackRadar Ingest Key is required.');
        }
    }

    public function send(string $method, string $path, array $payload, float $timeout = 2.0): void
    {
        if (! in_array($method, ['POST', 'PUT'], true) || ! str_starts_with($path, '/v1/') || str_contains($path, '?')
            || ! is_finite($timeout) || $timeout <= 0 || $timeout > 10) {
            throw new InvalidArgumentException('Invalid ingest request or timeout.');
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($body) > 1048576) {
            throw new TransportException('The StackRadar request exceeds 1 MiB.', 413, reason: 'payload_too_large');
        }
        $handle = curl_init(rtrim($this->baseUrl, '/').$path);
        if ($handle === false) {
            throw new TransportException('Unable to initialise the StackRadar transport.');
        }
        $retryAfter = null;
        $response = '';
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$this->ingestKey,
                'Content-Type: application/json', 'Accept: application/json', 'Expect:',
            ],
            CURLOPT_USERAGENT => 'stackradar/php',
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use (&$response): int {
                $response .= substr($data, 0, max(0, 16384 - strlen($response)));

                return strlen($data);
            },
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $value = trim(substr($header, 12));
                    $date = strtotime($value);
                    $retryAfter = ctype_digit($value) ? min((int) $value, 604800)
                        : ($date === false ? null : max(0, min($date - time(), 604800)));
                }

                return strlen($header);
            },
        ]);
        if (curl_exec($handle) === false) {
            throw new TransportException('The StackRadar upload failed (network or timeout).');
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($status !== 204) {
            $decoded = json_decode($response, true);
            $reason = is_array($decoded) && is_string($decoded['code'] ?? null)
                && preg_match('/\A[a-z0-9_]{1,100}\z/', $decoded['code']) === 1 ? $decoded['code'] : null;
            throw new TransportException('StackRadar did not accept the request (HTTP '.$status.').', $status, $retryAfter, $reason);
        }
    }
}
