<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Contracts;

use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationRealtime\Data\SyncPage;
use Modules\CollaborationRealtime\Data\SyncSnapshot;

interface SyncFeed
{
    /** Cursor is scoped to authenticated user + institution, not an arbitrary client-supplied offset.
     * Recheck current permissions. Never advance past uncommitted work. Expired cursor => sync_cursor_expired.
     */
    public function read(ActorContext $context, PageRequest $page): SyncPage;

    /** Stable cut across all pages; authorization is checked on every page.
     * Subscribe/buffer, bootstrap, replay from returned cut, deduplicate.
     */
    public function bootstrap(ActorContext $context, PageRequest $page): SyncSnapshot;
}
