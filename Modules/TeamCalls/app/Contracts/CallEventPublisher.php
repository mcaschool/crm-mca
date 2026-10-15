<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Contracts;

use Modules\TeamCalls\Support\CallEnvelope;

/**
 * PUERTO hacia la publicación en tiempo real (CollaborationCore: RealtimePublisher → canales
 * private-collab.user.{id} y private-collab.call.{ulid}, bandeja de eventos y sincronización).
 * Se invoca DESPUÉS de confirmar la transacción y un fallo nunca deshace la operación.
 */
interface CallEventPublisher
{
    public function publish(CallEnvelope $envelope): void;
}
