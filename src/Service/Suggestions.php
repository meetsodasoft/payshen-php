<?php

declare(strict_types=1);

namespace Payshen\Service;

use Payshen\Page;
use Payshen\Payshen;

/**
 * The routing engine's suggestions. Accepting applies one the safe way (a
 * 10% routing test or draft rules), never repointing live traffic at once.
 */
final class Suggestions
{
    public function __construct(private readonly Payshen $client)
    {
    }

    /** @param array{state?: string|list<string>, limit?: int, before?: string} $params */
    public function list(array $params = []): Page
    {
        return Page::fromAnswer($this->client->request('GET', '/api/v1/suggestions' . Query::of($params)));
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/api/v1/suggestions/' . PathId::of($id))['data'] ?? [];
    }

    /**
     * A decided suggestion answers 409 `suggestion_not_open`.
     *
     * @param array{idempotency_key?: string} $options
     * @return array{data: array<string, mixed>, result: array<string, mixed>|null}
     */
    public function accept(string $id, array $options = []): array
    {
        $answer = $this->client->request('POST', '/api/v1/suggestions/' . PathId::of($id) . '/accept', [], $options);
        return ['data' => $answer['data'] ?? [], 'result' => is_array($answer['result'] ?? null) ? $answer['result'] : null];
    }

    /**
     * @param array{reason?: string} $input at most 500 characters
     * @param array{idempotency_key?: string} $options
     * @return array<string, mixed>
     */
    public function reject(string $id, array $input = [], array $options = []): array
    {
        return $this->client->request('POST', '/api/v1/suggestions/' . PathId::of($id) . '/reject', $input, $options)['data'] ?? [];
    }

    /**
     * Hide it for 7 days; it comes back open if the evidence still holds.
     *
     * @param array{idempotency_key?: string} $options
     * @return array<string, mixed>
     */
    public function snooze(string $id, array $options = []): array
    {
        return $this->client->request('POST', '/api/v1/suggestions/' . PathId::of($id) . '/snooze', [], $options)['data'] ?? [];
    }
}
