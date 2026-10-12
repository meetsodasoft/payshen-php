<?php

declare(strict_types=1);

namespace Payshen\Exception;

/** 429 after the client's own retries: wait `$retryAfterSeconds` (when the API said) before the next call. */
class RateLimitException extends PayshenException
{
    public function __construct(
        string $errorCode,
        string $message,
        public readonly ?int $retryAfterSeconds,
    ) {
        parent::__construct(429, $errorCode, $message);
    }
}
