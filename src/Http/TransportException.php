<?php

declare(strict_types=1);

namespace Payshen\Http;

/** No answer came: the client retries it, then raises `NetworkException`. */
final class TransportException extends \RuntimeException
{
}
