<?php

declare(strict_types=1);

namespace Modules\Social\Support;

use Carbon\CarbonImmutable;
use Modules\Core\Scheduling\SchedulerHeartbeat;

/**
 * Estado del procesamiento automático para el panel, con sus cuatro planos separados:
 * planificador operativo · worker (última ejecución real) · asesor automático habilitado o no ·
 * trabajos de la cola (listos, retrasados, en ejecución, fallidos). El panel ya no depende de que
 * el worker permanezca activo artificialmente: lo que garantiza el despacho es el planificador.
 */
final class AdvisorServiceStatus
{
    /**
     * @return array{
     *   scheduler: array{running: bool, last_seen: ?CarbonImmutable},
     *   worker: array{recent: bool, last_seen: ?CarbonImmutable},
     *   autoreply_enabled: bool,
     *   queue_persistent: bool,
     *   ready: bool,
     *   pending_reason: ?string,
     *   jobs: array{ready: int, delayed: int, reserved: int, failed: int, total: int}
     * }
     */
    public static function snapshot(): array
    {
        $plan = AdvisorQueuePlan::inspect();

        return [
            'scheduler' => ['running' => SchedulerHeartbeat::running(), 'last_seen' => SchedulerHeartbeat::lastSeen()],
            'worker' => ['recent' => AdvisorDispatcher::workerRunning(), 'last_seen' => AdvisorDispatcher::lastSeen()],
            'autoreply_enabled' => AdvisorDispatcher::autoreplyEnabled(),
            'queue_persistent' => AdvisorDispatcher::queueIsPersistent(),
            'ready' => AdvisorDispatcher::ready(),
            'pending_reason' => AdvisorDispatcher::pendingReason(),
            'jobs' => [
                'ready' => $plan->ready,
                'delayed' => $plan->delayed,
                'reserved' => $plan->reserved,
                'failed' => AdvisorQueuePlan::failedCount(),
                'total' => $plan->total(),
            ],
        ];
    }
}
