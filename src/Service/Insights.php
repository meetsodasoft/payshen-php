<?php

declare(strict_types=1);

namespace Payshen\Service;

use Payshen\Payshen;

/**
 * What to change next, ranked by the money it is about: the dashboard's
 * Insights, for the key's environment. Rate limited to 20 calls a minute:
 * poll it daily, not per payment.
 */
final class Insights
{
    public function __construct(private readonly Payshen $client)
    {
    }

    /**
     * @param array{range?: string} $params
     * @return array{data: list<array<string, mixed>>, summary: array<string, mixed>|null}
     */
    public function list(array $params = []): array
    {
        $answer = $this->client->request('GET', '/api/v1/insights' . Query::of($params));
        return [
            'data' => is_array($answer['data'] ?? null) ? array_values($answer['data']) : [],
            'summary' => is_array($answer['summary'] ?? null) ? $answer['summary'] : null,
        ];
    }
}
