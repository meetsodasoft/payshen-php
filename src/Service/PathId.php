<?php

declare(strict_types=1);

namespace Payshen\Service;

/** @internal An id checked before it becomes a path segment. */
final class PathId
{
    /**
     * Encoding alone is not enough: `.` and `..` pass rawurlencode unchanged,
     * and `get('.')` would read the list while `refund('..')` would post to
     * another route. Payshen ids are letters, digits, `_` and `-`.
     */
    public static function of(string $id, string $name = 'id'): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id) !== 1) {
            throw new \InvalidArgumentException(sprintf('%s must be 1-128 letters, digits, "_" or "-".', $name));
        }
        return $id;
    }
}
