<?php

declare(strict_types=1);

namespace Payshen\Exception;

/**
 * No answer: a timeout, a refused connection, a TLS failure. On a POST the
 * operation may or may not have happened; retry with the SAME idempotency key
 * and the API answers what the first call did.
 */
class NetworkException extends PayshenException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(0, 'network_error', $message, $previous);
    }
}
