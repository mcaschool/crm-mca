<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use DateTimeImmutable;
use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\CollaborationRealtime\Data\EventEnvelope;

/**
 * Traduce un hecho de TeamCalls al sobre OFICIAL de CollaborationRealtime (EventEnvelope v1 + DeliveryAudience).
 *
 *  - id: el del hecho (ULID único, el mismo que en collab_call_events): repetir = mismo evento.
 *  - aggregate: call / id de la llamada / versión = contador persistente por llamada (orden por agregado).
 *  - correlation_id / causation_id: los de la operación en curso (OperationContext).
 *  - data: lista CERRADA de campos públicos; nunca dispositivos, sesiones, salas ni credenciales.
 *  - audiencia: participantes autorizados (o los indicados), sin el dispositivo excluido, y caducidad para
 *    los avisos de llamada entrante.
 */
final class CallEventEnvelopes
{
    /** Campos de data que pueden salir de TeamCalls (el resto queda solo en collab_call_events). */
    private const PUBLIC_DATA = ['call_id', 'user_id', 'media', 'scope', 'status', 'reason', 'applied', 'rejected', 'participants'];

    /** Tipos de TeamCalls y si son efímeros (solo tiempo real, sin historial). */
    public const TYPES = [
        'call.started' => false, 'call.ringing' => false, 'call.accepted' => false, 'call.declined' => false,
        'call.missed' => false, 'call.ended' => false, 'call.connected' => false,
        'call.participant.connecting' => false, 'call.participant.joined' => false, 'call.participant.left' => false,
        'call.participant.revoked' => false,
        'call.media.joined' => false, 'call.media.left' => false, 'call.media.room_finished' => false, 'call.media.room_rotated' => false,
        'call.answered_elsewhere' => true,
    ];

    /** @param array<string, mixed> $data */
    public static function event(
        string $id,
        string $type,
        string $callId,
        int $institutionId,
        int $sequence,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        ?string $causationId,
        array $data,
    ): EventEnvelope {
        return new EventEnvelope($id, $type, 1, $institutionId, 'call', $callId, $sequence, $actorId, $occurredAt,
            $correlationId, $causationId, self::publicData($callId, $data));
    }

    /** @param list<int> $userIds */
    public static function audience(int $institutionId, array $userIds, ?string $exceptDeviceId, ?DateTimeImmutable $expiresAt): DeliveryAudience
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        sort($ids);

        return new DeliveryAudience($institutionId, $ids, $exceptDeviceId, $expiresAt);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function publicData(string $callId, array $data): array
    {
        $out = ['call_id' => $callId];
        foreach (self::PUBLIC_DATA as $key) {
            if ($key !== 'call_id' && array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }

        return $out;
    }
}
