<?php

declare(strict_types=1);

namespace Payshen;

/**
 * An in-process store, for tests and local development. Wrong in production:
 * PHP forgets it at the end of every request, and it is not shared between
 * workers. `$maxEntries` bounds the memory it can hold.
 */
final class MemoryWebhookEventStore implements WebhookEventStore
{
    /** @var array<string, true> insertion order is eviction order */
    private array $seen = [];

    public function __construct(private readonly int $maxEntries = 10000)
    {
    }

    public function claim(string $eventId): bool
    {
        if (isset($this->seen[$eventId])) {
            return false;
        }
        if (count($this->seen) >= $this->maxEntries) {
            unset($this->seen[array_key_first($this->seen)]);
        }
        $this->seen[$eventId] = true;
        return true;
    }
}
