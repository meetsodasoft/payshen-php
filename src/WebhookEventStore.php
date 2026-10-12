<?php

declare(strict_types=1);

namespace Payshen;

/**
 * Where you record which events you have already handled.
 *
 * One method, `claim`, rather than a "seen" and "remember" pair: between a
 * read and a write, a second delivery of the same event reads "not seen" as
 * well and both handlers proceed. Retries make concurrent duplicates likely:
 * a slow endpoint is exactly the one that gets retried. So the store does the
 * whole thing in one atomic step, which your database does in one statement:
 *
 *     INSERT INTO processed_webhook_events (id) VALUES (?)
 *     ON CONFLICT (id) DO NOTHING RETURNING id;      -- PostgreSQL
 *     INSERT IGNORE INTO processed_webhook_events (id) VALUES (?);  -- MySQL: affected rows 1 or 0
 *
 * Redis: `SET <id> 1 NX`.
 */
interface WebhookEventStore
{
    /** True when THIS call recorded the id (do the work), false when it was already there. Must be atomic. */
    public function claim(string $eventId): bool;
}
