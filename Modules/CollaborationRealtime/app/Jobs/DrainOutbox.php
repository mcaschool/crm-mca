<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\CollaborationRealtime\Services\NotificationDelivery;
use Modules\CollaborationRealtime\Services\OutboxWorker;

final class DrainOutbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $institutionId)
    {
        $this->onQueue('collaboration');
        $this->afterCommit();
    }

    public function handle(OutboxWorker $worker, NotificationDelivery $notifications): void
    {
        for ($i = 0; $i < 20 && $worker->runOne($this->institutionId); $i++) {
        }
        $notifications->reconcile($this->institutionId);
    }
}
