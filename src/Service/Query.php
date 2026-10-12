<?php

declare(strict_types=1);

namespace Payshen\Service;

/** @internal A query string from the parameters that are set. */
final class Query
{
    /** @param array<string, scalar|list<scalar>|null> $params */
    public static function of(array $params): string
    {
        $set = [];
        foreach ($params as $name => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $set[$name] = implode(',', array_map('strval', $value));
            } elseif (is_bool($value)) {
                $set[$name] = $value ? 'true' : 'false';
            } else {
                $set[$name] = (string) $value;
            }
        }
        return $set === [] ? '' : '?' . http_build_query($set, '', '&', PHP_QUERY_RFC3986);
    }
}
