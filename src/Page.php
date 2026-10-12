<?php

declare(strict_types=1);

namespace Payshen;

/**
 * One page of a list, newest first. Pass `$nextCursor` as `before` for the
 * next page, or use the service's `all()` to walk every page.
 */
final class Page
{
    /**
     * @param list<array<string, mixed>> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly ?string $nextCursor,
        public readonly bool $hasMore,
    ) {
    }

    /** @param array<string, mixed> $answer */
    public static function fromAnswer(array $answer): self
    {
        $data = is_array($answer['data'] ?? null) ? array_values($answer['data']) : [];
        // The cursor is top level, beside `data` (`meta.next_cursor` read as a fallback).
        $cursor = $answer['next_cursor'] ?? ($answer['meta']['next_cursor'] ?? null);
        $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
        $hasMore = is_bool($answer['has_more'] ?? null) ? $answer['has_more'] : $cursor !== null;
        return new self($data, $cursor, $hasMore);
    }
}
