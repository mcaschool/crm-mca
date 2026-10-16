<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Contracts;

use Modules\CollaborationCore\Events\RevocationNotice;

/** Internal port implemented by CollaborationRealtime. Requires the active business transaction.
 * No network. Rollback removes notice and outbox; returns the durable event ID.
 */
interface RevocationRecorder
{
    public function record(RevocationNotice $notice): string;
}
