<?php

declare(strict_types=1);

namespace Payshen\Service;

use Payshen\Page;
use Payshen\Payshen;

/** The organization's event feed, newest first: the events webhooks deliver, for polling. */
final class Events
{
    public function __construct(private readonly Payshen $client)
    {
    }

    /** @param array{limit?: int, before?: string, type?: string} $params */
    public function list(array $params = []): Page
    {
        return Page::fromAnswer($this->client->request('GET', '/api/v1/events' . Query::of($params)));
    }

    /**
     * @param array{limit?: int, before?: string, type?: string} $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $params = []): \Generator
    {
        do {
            $page = $this->list($params);
            foreach ($page->data as $event) {
                yield $event;
            }
            $params['before'] = $page->nextCursor;
        } while ($page->hasMore && $page->nextCursor !== null);
    }
}
