<?php

declare(strict_types=1);

namespace Payshen\Http;

/**
 * Sends one HTTP request. The client decides retries, idempotency and errors;
 * a transport only carries bytes, and throws `TransportException` when no
 * answer came at all (a timeout, a refused connection, TLS).
 *
 * Pass your own to the client's `transport` option to route calls through a
 * proxy, a PSR-18 client or a recorder in tests.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws TransportException when no answer came
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): Response;
}
