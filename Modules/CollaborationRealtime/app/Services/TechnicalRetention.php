<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Illuminate\Support\Facades\DB;

/** Removes expired transient payloads but retains ID/hash/version tombstones and durable history. */
final class TechnicalRetention
{
    public function prune(int $institutionId): int
    {
        $ids = DB::table('collab_events as e')->join('collab_outbox as o', 'o.event_id', '=', 'e.id')
            ->where('e.institution_id', $institutionId)->where('e.ephemeral', true)->where('e.expires_at', '<', now()->subDay())
            ->whereIn('o.status', ['expired', 'published', 'suppressed', 'failed'])->whereRaw("JSON_LENGTH(JSON_EXTRACT(e.envelope, '$.data')) > 0")
            ->limit(500)->pluck('e.id');
        foreach ($ids as $id) {
            DB::table('collab_events')->where('institution_id', $institutionId)->where('id', $id)
                ->update(['envelope' => DB::raw("JSON_SET(envelope, '$.data', JSON_OBJECT())")]);
        }
        DB::table('collab_notification_delivery')->where('institution_id', $institutionId)->where('updated_at', '<', now()->subDays(30))->delete();

        return $ids->count();
    }
}
