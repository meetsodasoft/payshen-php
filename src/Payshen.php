<?php

declare(strict_types=1);

namespace Payshen;

use Payshen\Exception\AuthenticationException;
use Payshen\Exception\NetworkException;
use Payshen\Exception\PayshenException;
use Payshen\Exception\RateLimitException;
use Payshen\Exception\ValidationException;
use Payshen\Http\CurlTransport;
use Payshen\Http\Response;
use Payshen\Http\Transport;
use Payshen\Http\TransportException;
use Payshen\Service\Events;
use Payshen\Service\Insights;
use Payshen\Service\Merchants;
use Payshen\Service\Payments;
use Payshen\Service\Suggestions;

/**
 * The Payshen API client.
 *
 *     $payshen = new Payshen\Payshen('ps_test_...');
 *     $payment = $payshen->payments->create([...], ['idempotency_key' => 'order-1024']);
 *
 * Every POST carries an Idempotency-Key (yours, or one generated per call and
 * reused across this client's own retries), so a retry after a dropped
 * connection never charges twice. Answers are the API's JSON as arrays.
 */
final class Payshen
{
    public const VERSION = '0.1.2';

    /** HTTP statuses worth another attempt, as in the TypeScript SDK. */
    private const RETRY_STATUSES = [408, 425, 429, 500, 502, 503, 504];

    /**
     * Error codes a retry cannot improve although their status is retryable:
     * the API says not to retry them, and retrying did harm.
     */
    private const FINAL_CODES = [
        'payment_result_unknown',
        'state_unconfirmed',
        'state_conflict',
        'capture_result_unknown',
        'void_result_unknown',
        'refund_result_unknown',
        'psp_error',
        'provider_unavailable',
        'merchant_limit',
    ];

    private const BACKOFF_BASE_MS = 250;
    private const BACKOFF_CAP_MS = 4000;

    public readonly Payments $payments;
    public readonly Events $events;
    public readonly Insights $insights;
    public readonly Suggestions $suggestions;
    public readonly Merchants $merchants;

    private readonly string $baseUrl;
    private readonly float $timeout;
    private readonly int $maxRetries;
    private readonly Transport $transport;
    /** @var callable(int): void */
    private $sleep;

    /**
     * @param array{
     *     base_url?: string,
     *     timeout?: float|int,
     *     max_retries?: int,
     *     transport?: Transport,
     *     ca_bundle?: string,
     *     sleep?: callable(int): void,
     * } $options `timeout` in seconds (default 20); `max_retries` (default 3);
     *            `ca_bundle`: a PEM file of trusted roots, for a PHP with none configured;
     *            `sleep` receives milliseconds (tests pass a recorder).
     */
    public function __construct(private readonly string $apiKey, array $options = [])
    {
        if ($apiKey === '' || !str_starts_with($apiKey, 'ps_')) {
            throw new \InvalidArgumentException('A Payshen API key (ps_test_... or ps_live_...) is required.');
        }
        $this->baseUrl = rtrim($options['base_url'] ?? 'https://www.payshen.com', '/');
        // The key travels in a header on every request: over plain HTTP anything
        // on the path can read it. Localhost is exempt, for a mock server.
        if (preg_match('#^http://#i', $this->baseUrl) === 1
            && preg_match('#^http://(localhost|127\.0\.0\.1|\[::1\])(:|/|$)#i', $this->baseUrl) !== 1) {
            throw new \InvalidArgumentException('Payshen base_url must use https: an API key sent over plain HTTP is readable in transit.');
        }
        // Any other scheme (ftp://, file://...) is never the API, and curl would speak it.
        if (preg_match('#^https?://#i', $this->baseUrl) !== 1) {
            throw new \InvalidArgumentException('Payshen base_url must be an https URL.');
        }
        $this->timeout = (float) ($options['timeout'] ?? 20);
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 3));
        $this->transport = $options['transport'] ?? new CurlTransport(10.0, $options['ca_bundle'] ?? null);
        $this->sleep = $options['sleep'] ?? static function (int $ms): void {
            usleep($ms * 1000);
        };

        $this->payments = new Payments($this);
        $this->events = new Events($this);
        $this->insights = new Insights($this);
        $this->suggestions = new Suggestions($this);
        $this->merchants = new Merchants($this);
    }

    /**
     * Call any endpoint and get the whole answer (`data`, and `next_cursor`,
     * `summary` or `result` where the endpoint sends them). The services above
     * are thin wrappers over this.
     *
     * @param array<string, mixed>|null $body JSON body; an empty array is sent as `{}`
     * @param array{idempotency_key?: string} $options
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $options = []): array
    {
        $method = strtoupper($method);
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'payshen-php/' . self::VERSION . ' PHP/' . PHP_VERSION,
        ];
        $encoded = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            // json_encode([]) is "[]", which the API refuses as a body: an
            // empty body is the empty object.
            $encoded = json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        // Without a key, a retry after a connection dropped AFTER the server
        // created the payment would create a second one. The caller's key wins:
        // only the caller knows whether two calls are the same operation.
        // The key goes into a header line: CR or LF in it would add headers of
        // the caller's (or an attacker's) choosing. The API's own rule, checked
        // here so a bad key never leaves the process.
        if (isset($options['idempotency_key'])
            && (!is_string($options['idempotency_key']) || preg_match('/^[\x20-\x7E]{8,200}$/', $options['idempotency_key']) !== 1)) {
            throw new \InvalidArgumentException('idempotency_key must be 8-200 printable ASCII characters.');
        }
        if ($method === 'POST') {
            $headers['Idempotency-Key'] = $options['idempotency_key'] ?? 'sdk_' . bin2hex(random_bytes(16));
        } elseif (isset($options['idempotency_key'])) {
            $headers['Idempotency-Key'] = $options['idempotency_key'];
        }

        $url = $this->baseUrl . $path;
        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($method, $url, $headers, $encoded, $this->timeout);
            } catch (TransportException $e) {
                if ($attempt < $this->maxRetries) {
                    ($this->sleep)($this->backoff($attempt));
                    continue;
                }
                throw new NetworkException($e->getMessage(), $e);
            }

            $payload = $this->decode($response);
            if ($response->status >= 200 && $response->status < 300) {
                return $payload ?? [];
            }

            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $code = is_string($error['code'] ?? null) ? $error['code'] : null;
            $message = is_string($error['message'] ?? null) ? $error['message'] : null;
            $final = $code !== null && in_array($code, self::FINAL_CODES, true);
            $retryable = in_array($response->status, self::RETRY_STATUSES, true) && !$final;

            if ($response->status === 429) {
                $retryAfter = $this->retryAfter($response);
                if ($retryable && $attempt < $this->maxRetries) {
                    ($this->sleep)($this->backoff($attempt, $retryAfter));
                    continue;
                }
                throw $this->fail(new RateLimitException($code ?? 'rate_limited', $message ?? 'Rate limit exceeded.', $retryAfter), $response, $error);
            }
            if ($retryable && $attempt < $this->maxRetries) {
                ($this->sleep)($this->backoff($attempt));
                continue;
            }
            if ($response->status === 401 || $response->status === 403) {
                throw $this->fail(new AuthenticationException($response->status, $code ?? 'unauthorized', $message ?? 'Request not authorised.'), $response, $error);
            }
            if ($response->status === 422) {
                throw $this->fail(new ValidationException(422, $code ?? 'validation_error', $message ?? 'Validation error.'), $response, $error);
            }
            throw $this->fail(
                new PayshenException($response->status, $code ?? 'unknown_error', $message ?? sprintf('Request failed with status %d.', $response->status)),
                $response,
                $error,
            );
        }
    }

    /** @return array<string, mixed>|null */
    private function decode(Response $response): ?array
    {
        if ($response->body === '') {
            return null;
        }
        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Every error leaves carrying the id support will ask for.
     *
     * @template E of PayshenException
     * @param E $e
     * @param array<string, mixed> $error
     * @return E
     */
    private function fail(PayshenException $e, Response $response, array $error): PayshenException
    {
        $e->requestId = $response->header('x-request-id');
        if (is_array($error['excluded'] ?? null)) {
            $e->excluded = array_values($error['excluded']);
        }
        if (is_string($error['refund_id'] ?? null)) {
            $e->refundId = $error['refund_id'];
        }
        return $e;
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('retry-after');
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    /** Milliseconds: exponential with jitter, and a server's Retry-After capped like any other wait. */
    private function backoff(int $attempt, ?int $retryAfterSeconds = null): int
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            return min(self::BACKOFF_CAP_MS, $retryAfterSeconds * 1000);
        }
        $exp = min(self::BACKOFF_CAP_MS, self::BACKOFF_BASE_MS * (2 ** $attempt));
        return (int) round($exp + (mt_rand() / mt_getrandmax()) * $exp * 0.3);
    }
}
