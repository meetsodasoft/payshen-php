<?php

declare(strict_types=1);

namespace Payshen\Exception;

/**
 * An answer from the API that is not a success, or a request that never got one.
 *
 * `$errorCode` is the API's own code (`refund_pending`, `not_capturable`,
 * `invalid_api_key`, ...): branch on it, not on the message. `$status` is the
 * HTTP status (0 when no answer came). Quote `$requestId` in a support
 * conversation: it is the difference between "a payment failed around 14:32"
 * and one row in a log.
 */
class PayshenException extends \RuntimeException
{
    /** The `X-Request-Id` of the failed call, when the API returned one. */
    public ?string $requestId = null;

    /**
     * On `routing_failed`: every connection that was considered and why it was
     * left out, as the API sent it.
     *
     * @var list<array<string, mixed>>|null
     */
    public ?array $excluded = null;

    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }
}
