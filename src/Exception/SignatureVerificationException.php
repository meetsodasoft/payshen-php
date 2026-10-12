<?php

declare(strict_types=1);

namespace Payshen\Exception;

/**
 * A webhook delivery that did not verify: `missing_signature`,
 * `malformed_signature`, `expired_signature`, `missing_secret`,
 * `invalid_signature`, or `malformed_event` for a verified body that is not a
 * Payshen event. Answer the delivery 400 and do not act on it.
 */
class SignatureVerificationException extends AuthenticationException
{
    public function __construct(string $errorCode, string $message)
    {
        parent::__construct(400, $errorCode, $message);
    }
}
