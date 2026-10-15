<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Data;

final readonly class SyncPage
{
    /** @param list<EventEnvelope> $events */
    public function __construct(public array $events, public string $cursor, public bool $hasMore)
    {
        if (! array_is_list($events) || $cursor === '' || strlen($cursor) > 4096) {
            throw new \InvalidArgumentException('Invalid sync page.');
        }
        foreach ($events as $event) {
            if (! $event instanceof EventEnvelope) {
                throw new \InvalidArgumentException('Invalid sync event.');
            }
        }
    }
}
