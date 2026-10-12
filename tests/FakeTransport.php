<?php

declare(strict_types=1);

namespace Payshen\Tests;

use Payshen\Http\Response;
use Payshen\Http\Transport;
use Payshen\Http\TransportException;

/** Answers from a queue and records every request, so a test reads exactly what was sent. */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float}> */
    public array $sent = [];

    /** @var list<Response|TransportException> */
    private array $answers;

    public function __construct(Response|TransportException ...$answers)
    {
        $this->answers = $answers;
    }

    /** @param array<string, mixed>|null $body */
    public static function json(int $status, ?array $body, array $headers = []): Response
    {
        return new Response($status, array_change_key_case($headers, CASE_LOWER), $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): Response
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeoutSeconds];
        $next = array_shift($this->answers);
        if ($next === null) {
            throw new \LogicException('FakeTransport: no answer queued for ' . $method . ' ' . $url);
        }
        if ($next instanceof TransportException) {
            throw $next;
        }
        return $next;
    }
}
