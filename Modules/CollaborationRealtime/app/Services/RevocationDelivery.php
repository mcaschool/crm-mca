<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use Modules\CollaborationRealtime\Integration\RevocationConsumer;

final class RevocationDelivery
{
    public function deliver(EventEnvelope $event): void
    {
        (new RevocationPolicy)->validate($event);
        if (! app()->bound(RevocationConsumer::class)) {
            throw new \RuntimeException('TeamCalls revocation consumer is not installed.');
        }
        app(\Modules\Core\Tenancy\CurrentInstitution::class)->runFor($event->institutionId, fn () => DB::transaction(function () use ($event): void {
            DB::table('institutions')->where('id', $event->institutionId)->lockForUpdate()->firstOrFail();
            if (DB::table('collab_revocation_receipts')->where('event_id', $event->id)->exists()) {
                return;
            }
            app(RevocationConsumer::class)->consume($event);
            DB::table('collab_revocation_receipts')->insert(['event_id' => $event->id, 'institution_id' => $event->institutionId, 'accepted_at' => now()]);
        }, 3));
    }
}
