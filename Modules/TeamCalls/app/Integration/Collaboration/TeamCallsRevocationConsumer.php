<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use Illuminate\Support\Carbon;
use LogicException;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use Modules\CollaborationRealtime\Integration\RevocationConsumer;
use Modules\TeamCalls\Services\CallService;
use Modules\TeamCalls\Support\ConversationRef;

/**
 * Consumidor de las revocaciones durables de MCA Colaboración 1.1 (outbox de CollaborationRealtime).
 *
 * Lo invoca RevocationDelivery DENTRO de su transacción, en la institución del evento, con el id del
 * sobre como clave de idempotencia (collab_revocation_receipts). Aquí solo hay efectos SQL de TeamCalls:
 * salida/expulsión de participantes, rotación de sala y sus eventos call.*, todos en esa transacción. Las
 * acciones contra LiveKit (retirar participante, cerrar la sala anterior) se ejecutan al confirmar; si
 * fallan, el estado persistido es la intención durable y `teamcalls:media-sweep` las reintenta (cierra las
 * salas que ya no son de ninguna llamada y retira a quien no puede estar en la sala).
 *
 * Eventos retrasados: solo afectan a admisiones ANTERIORES a la revocación (`occurredAt`). Una admisión
 * posterior ya superó una autorización vigente y no se expulsa. Los fallos se propagan (reintento del outbox).
 */
final class TeamCallsRevocationConsumer implements RevocationConsumer
{
    /** Ámbitos de conversación que pueden tener llamadas en TeamCalls (los equipos emiten cada canal). */
    private const CALL_SCOPES = ['direct', 'group', 'channel'];

    public function __construct(private readonly CallService $calls) {}

    public function consume(EventEnvelope $event): void
    {
        $userId = $event->data['user_id'] ?? null;
        $scope = $event->data['scope'] ?? null;
        $resource = $event->data['resource_id'] ?? null;
        if (! is_int($userId) || $userId < 1 || ! is_string($scope) || ! is_string($resource)) {
            throw new LogicException('Invalid revocation payload.');
        }
        $at = Carbon::instance($event->occurredAt);

        match ($event->type) {
            // El motivo (user_inactive, application_revoked…) queda en call.participant.revoked.
            'collaboration.access.revoked' => $this->calls->revokeAccess($userId, (string) ($event->data['reason'] ?? 'access_revoked'), $at),
            // Los identificadores de dispositivo no se reutilizan: revocar uno nunca afecta a otro.
            'collaboration.device.revoked' => $this->calls->revokeDevice($userId, $resource),
            'conversation.membership.revoked' => in_array($scope, self::CALL_SCOPES, true)
                ? $this->calls->revokeMembership(new ConversationRef($scope, $resource), $userId, $at)
                : 0,
            default => throw new LogicException('Unsupported revocation type.'),
        };
    }
}
