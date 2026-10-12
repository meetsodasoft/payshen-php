<?php

declare(strict_types=1);

namespace Payshen\Http;

/** What came back: the status, the headers (names lowercased) and the raw body. */
final class Response
{
    /**
     * @param array<string, string> $headers lowercased name => last value
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
