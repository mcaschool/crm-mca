<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use InvalidArgumentException;
use Modules\CollaborationCore\Contracts\ConversationDirectory;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use Modules\CollaborationRealtime\Integration\EventPolicy;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallParticipant;

/**
 * Política de CollaborationRealtime para los eventos call.* (un registro por tipo, ver
 * TeamCallsServiceProvider). TeamCalls es la autoridad sobre llamadas y participantes:
 *  - validate: esquema CERRADO por tipo (campos exactos y tipos estrictos); data.call_id = aggregate.id.
 *  - allows: misma institución; quien lee participa (o participó) en ESA llamada sin haber sido
 *    expulsado y, si la llamada nació en una conversación, sigue siendo miembro hoy (ConversationDirectory).
 *  - ephemeral: solo call.answered_elsewhere (aviso de tiempo real; el estado persistido lo reconcilia).
 */
final class CallEventPolicy implements EventPolicy
{
    /** Tipo => [obligatorios, opcionales] además de call_id. */
    public const SCHEMAS = [
        'call.started' => [['media', 'scope'], []],
        'call.ringing' => [['user_id', 'media'], []],
        'call.accepted' => [['user_id'], []],
        'call.answered_elsewhere' => [['user_id'], []],
        'call.declined' => [['user_id'], []],
        'call.missed' => [['user_id'], []],
        'call.connected' => [[], []],
        'call.ended' => [['status', 'reason'], []],
        'call.participant.connecting' => [['user_id'], []],
        'call.participant.joined' => [['user_id'], []],
        'call.participant.left' => [['user_id'], []],
        'call.participant.revoked' => [['user_id', 'reason'], []],
        'call.media.joined' => [['user_id', 'applied'], ['rejected']],
        'call.media.left' => [['user_id', 'applied'], ['rejected']],
        'call.media.room_finished' => [['participants'], []],
        'call.media.room_rotated' => [['reason'], []],
    ];

    private const STATUSES = ['ended', 'declined', 'cancelled', 'missed', 'failed'];

    /**
     * Vive dentro del registro singleton de EventPolicies: NO guarda CurrentInstitution (es «scoped» y Laravel
     * lo renueva en cada trabajo de cola, p. ej. DrainOutbox); lo resuelve en cada uso.
     */
    public function __construct(private readonly bool $ephemeral) {}

    public function validate(EventEnvelope $event): void
    {
        $schema = self::SCHEMAS[$event->type] ?? null;
        if ($schema === null || $this->ephemeral !== (CallEventEnvelopes::TYPES[$event->type] ?? false)
            || $event->version !== 1 || $event->aggregateType !== 'call' || ($event->data['call_id'] ?? null) !== $event->aggregateId) {
            throw new InvalidArgumentException('Invalid TeamCalls event.');
        }
        [$required, $optional] = $schema;
        $keys = array_keys($event->data);
        $missing = array_diff(['call_id', ...$required], $keys);
        $extra = array_diff($keys, ['call_id', ...$required, ...$optional]);
        if ($missing !== [] || $extra !== []) {
            throw new InvalidArgumentException('Invalid TeamCalls payload.');
        }
        foreach ($event->data as $key => $value) {
            $ok = match ($key) {
                'call_id' => is_string($value) && preg_match('/^[0-9A-Za-z]{26}$/', $value) === 1,
                'user_id', 'participants' => is_int($value) && $value >= ($key === 'user_id' ? 1 : 0),
                'media' => in_array($value, ['audio', 'video'], true),
                'scope' => in_array($value, ['direct', 'group'], true),
                'status' => in_array($value, self::STATUSES, true),
                'reason' => is_string($value) && preg_match('/^[a-z_]{1,32}$/', $value) === 1,
                'applied', 'rejected' => is_bool($value),
                default => false,
            };
            if (! $ok) {
                throw new InvalidArgumentException('Invalid TeamCalls field.');
            }
        }
    }

    public function allows(ActorContext $actor, EventEnvelope $event): bool
    {
        if (! isset(self::SCHEMAS[$event->type]) || $event->institutionId !== $actor->institutionId) {
            return false;
        }

        return (bool) app(CurrentInstitution::class)->runFor($event->institutionId, function () use ($actor, $event): bool {
            $call = Call::query()->whereKey($event->aggregateId)->first(['id', 'context_type', 'context_id']);
            $member = $call instanceof Call && CallParticipant::query()->where('call_id', $call->id)
                ->where('user_id', $actor->userId)->whereNull('revoked_at')->exists();
            if (! $member || $call->context_type === null || $call->context_id === null) {
                return $member;
            }
            // Llamada de conversación: la membresía se revalida en cada lectura (no se congela).
            if (! app()->bound(ConversationDirectory::class)) {
                return false;
            }
            try {
                return app(ConversationDirectory::class)->membership($actor,
                    new ConversationRef(ConversationType::from($call->context_type), $call->context_id), $actor->userId)->active;
            } catch (ContractException) {
                return false;
            }
        });
    }

    public function ephemeral(): bool
    {
        return $this->ephemeral;
    }
}
