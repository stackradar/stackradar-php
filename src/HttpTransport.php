<?php

declare(strict_types=1);

namespace StackRadar;

use InvalidArgumentException;

final class HttpTransport implements Transport
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $projectToken,
        private readonly float $timeout = 2.0,
    ) {
        $url = parse_url($endpoint);
        $local = in_array($url['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);

        if (! $url || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || (($url['scheme'] ?? '') !== 'https' && ! (($url['scheme'] ?? '') === 'http' && $local))) {
            throw new InvalidArgumentException('The StackRadar endpoint must use HTTPS (HTTP is allowed on loopback).');
        }

        if (trim($projectToken) === '' || strpbrk($projectToken, "\r\n") !== false) {
            throw new InvalidArgumentException('A valid StackRadar project token is required.');
        }

        if (! is_finite($timeout) || $timeout <= 0 || $timeout > 10) {
            throw new InvalidArgumentException('The StackRadar timeout must be greater than zero and at most 10 seconds.');
        }
    }

    public function send(array $payload): void
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $handle = curl_init($this->endpoint);
        $retryAfter = null;

        if ($handle === false) {
            throw new TransportException('Unable to initialise the StackRadar transport.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$this->projectToken,
                'Content-Type: application/json',
                'Accept: application/json',
                'Expect:',
            ],
            CURLOPT_USERAGENT => 'stackradar/php',
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($this->timeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ceil($this->timeout * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static fn ($curl, string $data): int => strlen($data),
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
            // Do not include the URL, token, response body or curl error text in exceptions.
            throw new TransportException('The StackRadar upload failed (network or timeout).');
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($status < 200 || $status >= 300) {
            throw new TransportException('StackRadar did not accept the report (HTTP '.$status.').', $status, $retryAfter);
        }
    }
}
