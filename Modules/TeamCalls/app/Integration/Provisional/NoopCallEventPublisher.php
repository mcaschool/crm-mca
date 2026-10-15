<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Provisional;

use Modules\TeamCalls\Contracts\CallEventPublisher;
use Modules\TeamCalls\Support\CallEnvelope;

/**
 * PROVISIONAL hasta el RealtimePublisher de CollaborationCore (WebSocket externo + bandeja de eventos).
 * La traza persistente (collab_call_events) y los eventos internos de Laravel sí funcionan ya.
 */
final class NoopCallEventPublisher implements CallEventPublisher
{
    public function publish(CallEnvelope $envelope): void {}
}
