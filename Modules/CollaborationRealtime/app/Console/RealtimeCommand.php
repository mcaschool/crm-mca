<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Console;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationRealtime\Services\NotificationDelivery;
use Modules\CollaborationRealtime\Services\OutboxWorker;
use Modules\Core\Console\TenantAwareCommand;

final class RealtimeCommand extends TenantAwareCommand
{
    protected $signature = 'collaboration:realtime {action : drain|metrics|retry|prune} {--institution=} {--all-institutions} {--event=} {--limit=100}';

    protected $description = 'Operate the persistent institutional outbox; no network before commit.';

    protected function handleForInstitution(int $institutionId): int
    {
        $query = DB::table('collab_outbox')->where('institution_id', $institutionId);
        switch ($this->argument('action')) {
            case 'drain':
                $limit = max(1, min(1000, (int) $this->option('limit')));
                for ($i = 0; $i < $limit && app(OutboxWorker::class)->runOne($institutionId); $i++) {
                }
                app(NotificationDelivery::class)->reconcile($institutionId, $limit);
                break;
            case 'metrics':
                $this->line(json_encode(['institution_id' => $institutionId, 'statuses' => $query->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'),
                    'oldest_pending_at' => DB::table('collab_outbox')->where('institution_id', $institutionId)->whereIn('status', ['pending', 'claimed'])->min('available_at'),
                    'mean_publish_ms' => DB::table('collab_outbox as o')->join('collab_events as e', 'e.id', '=', 'o.event_id')->where('o.institution_id', $institutionId)->whereNotNull('o.accepted_at')->selectRaw('AVG(TIMESTAMPDIFF(MICROSECOND, e.created_at, o.accepted_at))/1000 AS latency')->value('latency')], JSON_THROW_ON_ERROR));
                break;
            case 'retry':
                if (! is_string($this->option('event')) || $this->option('event') === '') {
                    return self::INVALID;
                }
                $query->where('event_id', $this->option('event'))->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'failure_code' => null]);
                break;
            case 'prune':
                // Only technical receipts; domain history retained until domain retention/erasure agreements.
                app(\Modules\CollaborationRealtime\Services\TechnicalRetention::class)->prune($institutionId);
                break;
            default:
                return self::INVALID;
        }

        return self::SUCCESS;
    }
}
