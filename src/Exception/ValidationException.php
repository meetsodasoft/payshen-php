<?php

declare(strict_types=1);

namespace Payshen\Exception;

/** 422: the request was understood and refused. `$errorCode` says which field or rule. */
class ValidationException extends PayshenException
{
}
