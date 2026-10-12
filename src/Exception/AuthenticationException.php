<?php

declare(strict_types=1);

namespace Payshen\Exception;

/** 401 or 403: the key is missing, wrong, revoked, or not allowed this call. */
class AuthenticationException extends PayshenException
{
}
