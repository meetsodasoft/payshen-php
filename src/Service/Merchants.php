<?php

declare(strict_types=1);

namespace Payshen\Service;

use Payshen\Payshen;

/**
 * White-label partners only: the merchants provisioned under your workspace.
 * Both calls answer 403 `not_a_partner` unless the partner flag is set on
 * your workspace; provisioning also requires a live key.
 */
final class Merchants
{
    public function __construct(private readonly Payshen $client)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $answer = $this->client->request('GET', '/api/v1/merchants');
        return is_array($answer['data'] ?? null) ? array_values($answer['data']) : [];
    }

    /**
     * Provision a merchant and invite its owner. Re-inviting an owner with a
     * pending invitation answers 409 `already_invited`, so a retry is safe.
     *
     * @param array<string, mixed> $input
     * @param array{idempotency_key?: string} $options
     * @return array<string, mixed>
     */
    public function create(array $input, array $options = []): array
    {
        return $this->client->request('POST', '/api/v1/merchants', $input, $options)['data'] ?? [];
    }
}
