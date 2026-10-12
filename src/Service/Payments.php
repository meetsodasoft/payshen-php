<?php

declare(strict_types=1);

namespace Payshen\Service;

use Payshen\Page;
use Payshen\Payshen;

/**
 * Payments: create, read, list, and the operations on one.
 *
 * `$options` on a POST takes `idempotency_key`. Pass your own (your order id,
 * `capture-<order>`, `refund-<order>-1`) to make a retry across processes
 * safe; the client generates one per call otherwise.
 */
final class Payments
{
    public function __construct(private readonly Payshen $client)
    {
    }

    /**
     * Create and route a payment. A hosted payment answers `REQUIRES_ACTION`
     * with `next_action.url`: send the customer there.
     *
     * @param array<string, mixed> $input amount (minor units), currency, country, reference, return_url, capture_method, ...
     * @param array{idempotency_key?: string} $options
     * @return array<string, mixed> the payment
     */
    public function create(array $input, array $options = []): array
    {
        return $this->client->request('POST', '/api/v1/payments', $input, $options)['data'] ?? [];
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/api/v1/payments/' . rawurlencode($id))['data'] ?? [];
    }

    /**
     * One page, newest first, in the key's environment.
     *
     * @param array{limit?: int, before?: string, status?: string} $params
     */
    public function list(array $params = []): Page
    {
        return Page::fromAnswer($this->client->request('GET', '/api/v1/payments' . Query::of($params)));
    }

    /**
     * Every payment, page by page, as one sequence.
     *
     * @param array{limit?: int, before?: string, status?: string} $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $params = []): \Generator
    {
        do {
            $page = $this->list($params);
            foreach ($page->data as $payment) {
                yield $payment;
            }
            $params['before'] = $page->nextCursor;
        } while ($page->hasMore && $page->nextCursor !== null);
    }

    /**
     * The payment's timeline: created, authorized, captured, refunded, ...
     *
     * @return list<array<string, mixed>>
     */
    public function events(string $id): array
    {
        $answer = $this->client->request('GET', '/api/v1/payments/' . rawurlencode($id) . '/events');
        return is_array($answer['data'] ?? null) ? array_values($answer['data']) : [];
    }

    /**
     * Capture an authorized payment, in full (no amount) or in part.
     *
     * A provider that only accepts it first answers with the payment
     * `PENDING`; it becomes `CAPTURED` when the provider confirms, and
     * `payment.captured` follows.
     *
     * @param array{amount?: int} $input
     * @param array{idempotency_key?: string} $options
     * @return array<string, mixed> the payment's status and figures after the operation
     */
    public function capture(string $id, array $input = [], array $options = []): array
    {
        return $this->client->request('POST', '/api/v1/payments/' . rawurlencode($id) . '/capture', $input, $options)['data'] ?? [];
    }

    /**
     * Release an authorization before capture.
     *
     * @param array{idempotency_key?: string} $options
     * @return array<string, mixed>
     */
    public function void(string $id, array $options = []): array
    {
        return $this->client->request('POST', '/api/v1/payments/' . rawurlencode($id) . '/void', [], $options)['data'] ?? [];
    }

    /**
     * Refund a captured payment, in full (no amount) or in part.
     *
     * Most providers confirm a refund a moment later: the API then answers
     * `409 refund_pending`, thrown here as a `PayshenException` with that
     * `errorCode`. It is accepted, not failed. Do not send it again under a
     * new key: `payment.refunded` or `payment.refund_failed` follows, and the
     * same key answers the final result once it is settled.
     *
     * @param array{amount?: int} $input
     * @param array{idempotency_key?: string} $options
     * @return array<string, mixed>
     */
    public function refund(string $id, array $input = [], array $options = []): array
    {
        return $this->client->request('POST', '/api/v1/payments/' . rawurlencode($id) . '/refunds', $input, $options)['data'] ?? [];
    }
}
