<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Models\CallParticipant;

/**
 * Autorización de los eventos call.* para CollaborationRealtime. El contrato 1.0 solo fija el sobre
 * (EventEnvelope) y la audiencia; la interfaz oficial de políticas por tipo de evento aún no está en los
 * contratos, así que esto queda listo para envolverla cuando se publique, sin registrarse en ningún
 * sitio. Regla: solo tipos de TeamCalls, de la misma institución, y para quien participa (o participó)
 * en ESA llamada sin haber sido expulsado; nada más del agregado se revela.
 */
final class CallEventAccess
{
    public function __construct(private readonly CurrentInstitution $institution) {}

    public function supports(EventEnvelope $event): bool
    {
        return $event->aggregateType === 'call' && array_key_exists($event->type, CallEventEnvelopes::TYPES);
    }

    public function ephemeral(EventEnvelope $event): bool
    {
        return CallEventEnvelopes::TYPES[$event->type] ?? false;
    }

    public function allows(ActorContext $viewer, EventEnvelope $event): bool
    {
        if (! $this->supports($event) || $event->institutionId !== $viewer->institutionId) {
            return false;
        }

        // En la institución del evento (ámbito institucional normal, sin saltarse el aislamiento).
        return (bool) $this->institution->runFor($event->institutionId, fn (): bool => CallParticipant::query()
            ->where('call_id', $event->aggregateId)
            ->where('user_id', $viewer->userId)
            ->whereNull('revoked_at')
            ->exists());
    }
}
