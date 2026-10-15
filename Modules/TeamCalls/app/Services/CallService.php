<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Contracts\ConversationAccess;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Contracts\Presence;
use Modules\TeamCalls\Contracts\PushNotifier;
use Modules\TeamCalls\Contracts\UserDirectory;
use Modules\TeamCalls\Domain\CallStateMachine;
use Modules\TeamCalls\Enums\CallMedia;
use Modules\TeamCalls\Enums\CallScope;
use Modules\TeamCalls\Enums\CallStatus;
use Modules\TeamCalls\Enums\ParticipantRole;
use Modules\TeamCalls\Enums\ParticipantState;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallEvent;
use Modules\TeamCalls\Models\CallParticipant;
use Modules\TeamCalls\Support\CallSummary;
use Modules\TeamCalls\Support\CallTarget;
use Modules\TeamCalls\Support\ConversationRef;
use Modules\TeamCalls\Support\JoinCredentials;
use Modules\TeamCalls\Support\MediaGrant;
use Throwable;

/**
 * Motor de llamadas internas. Reglas que se cumplen en TODAS las operaciones:
 *
 *  - Quien actúa: usuario activo, con acceso a las llamadas y de la institución activa. Las llamadas
 *    de otra institución no existen para él (scope global + «not_found», sin revelar nada).
 *  - Participantes decididos por el SERVIDOR (miembros de la conversación o personas validadas una a
 *    una); el cliente nunca impone quién está autorizado.
 *  - Cada cambio se hace en una transacción con la llamada y sus participantes BLOQUEADOS
 *    (lockForUpdate) y con transiciones validadas por CallStateMachine. Repetir una operación es
 *    idempotente (misma respuesta, sin eventos nuevos).
 *  - Multidispositivo: contestar fija el dispositivo; una respuesta posterior desde otro dispositivo
 *    recibe «answered_elsewhere» y no crea una segunda aceptación.
 *  - Reintentos de red: iniciar con la misma clave de idempotencia devuelve la misma llamada.
 *  - Estados coherentes sin procesos permanentes: antes de cualquier operación o consulta se aplica lo
 *    vencido (invitaciones sin contestar → perdidas; conexión que no llega → fallida). Además, el
 *    comando teamcalls:expire-stale lo hace cada minuto para las llamadas que nadie consulta.
 */
final class CallService
{
    public function __construct(
        private readonly UserDirectory $users,
        private readonly ConversationAccess $conversations,
        private readonly CallRecorder $recorder,
        private readonly Presence $presence,
        private readonly PushNotifier $push,
        private readonly MediaRoomService $media,
        private readonly CurrentInstitution $context,
    ) {}

    // ─── Inicio e invitaciones ──────────────────────────────────────────────────────────────────

    public function start(User $initiator, CallTarget $target, CallMedia $media, string $idempotencyKey, ?string $deviceId = null): Call
    {
        $this->assertActor($initiator);
        $key = $this->validKey($idempotencyKey);
        $deviceId = $this->validDevice($deviceId, required: false);
        $hash = hash('sha256', $target->fingerprint().'|'.$media->value);

        $existing = $this->byIdempotency($initiator, $key);
        if ($existing !== null) {
            return $this->sameRequest($existing, $hash);
        }

        [$scope, $context, $invitees] = $this->resolveTarget($initiator, $target);
        $max = $this->maxParticipants();
        if ($invitees->count() + 1 > $max) {
            throw CallException::tooManyParticipants($max);
        }
        if ($this->isBusy($initiator->id)) {
            throw CallException::busy();
        }

        try {
            return DB::transaction(function () use ($initiator, $media, $key, $hash, $deviceId, $scope, $context, $invitees): Call {
                $now = now();
                $call = new Call;
                $call->forceFill([
                    'institution_id' => $initiator->institution_id,
                    'initiator_id' => $initiator->id,
                    'media' => $media,
                    'scope' => $scope,
                    'context_type' => $context?->type,
                    'context_id' => $context?->id,
                    'status' => CallStatus::Initiating,
                    'room_name' => 'tc-'.Str::lower((string) Str::ulid()),
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                ])->save();

                $this->addParticipant($call, $initiator->id, ParticipantRole::Host, ParticipantState::Accepted, null, $deviceId);
                foreach ($invitees as $user) {
                    $this->addParticipant($call, $user->id, ParticipantRole::Member, ParticipantState::Ringing, $initiator->id, null);
                }
                $call->load('participants');

                $this->recorder->record($call, 'call.started', $initiator->id, null, ['media' => $media->value, 'scope' => $scope->value]);
                $this->moveCall($call, CallStatus::Ringing);
                $call->forceFill(['ringing_at' => $now])->save();
                $this->ring($call, $initiator->id, $invitees->pluck('id')->all());
                $this->presence->setInCall($initiator->id, $call->id, $this->ringSeconds() + $this->connectSeconds());

                return $call;
            });
        } catch (UniqueConstraintViolationException) {
            // Carrera entre dos reintentos con la misma clave: gana el primero; el segundo la devuelve.
            $existing = $this->byIdempotency($initiator, $key) ?? throw CallException::idempotencyConflict();

            return $this->sameRequest($existing, $hash);
        }
    }

    /**
     * Invita a más personas a una llamada viva. Solo quien está en la llamada puede invitar y solo a
     * personas autorizadas (miembros de la conversación de origen o contactos válidos).
     *
     * @param  array<int|string>  $userIds
     */
    public function invite(User $actor, string $callId, array $userIds): Call
    {
        $this->assertActor($actor);
        $ids = CallTarget::users($userIds)->userIds;

        return $this->locked($callId, function (Call $call) use ($actor, $ids): Call {
            $me = $this->participantOrFail($call, $actor->id);
            if (! $call->status->isLive()) {
                throw CallException::invalidTransition('invite on '.$call->status->value);
            }
            if (! $me->state->isIn()) {
                throw CallException::forbidden('Only people in the call can invite.');
            }

            $users = $this->authorizedInvitees($actor, $call, $ids);
            $brandNew = $users->filter(fn (User $u) => $call->participantFor($u->id) === null)->count();
            if ($call->participants->count() + $brandNew > $this->maxParticipants()) {
                throw CallException::tooManyParticipants($this->maxParticipants());
            }
            $newIds = [];
            foreach ($users as $user) {
                $p = $call->participantFor($user->id);
                if ($p === null) {
                    $this->addParticipant($call, $user->id, ParticipantRole::Member, ParticipantState::Ringing, $actor->id, null);
                    $newIds[] = $user->id;
                } elseif (in_array($p->state, [ParticipantState::Declined, ParticipantState::Missed, ParticipantState::Left], true)) {
                    // Volver a llamar a quien no contestó o salió: vuelve a sonar (idempotente si ya suena).
                    $p->forceFill(['state' => ParticipantState::Ringing, 'invited_by' => $actor->id, 'invited_at' => now(),
                        'ring_expires_at' => now()->addSeconds($this->ringSeconds()), 'responded_at' => null, 'device_id' => null])->save();
                    $newIds[] = $user->id;
                }
            }
            if ($newIds === []) {
                return $call;
            }
            if ($call->scope === CallScope::Direct) {
                $call->forceFill(['scope' => CallScope::Group])->save();
            }
            $call->load('participants');
            $this->ring($call, $actor->id, $newIds);

            return $call;
        });
    }

    // ─── Respuesta de cada persona ──────────────────────────────────────────────────────────────

    public function accept(User $user, string $callId, string $deviceId): Call
    {
        $this->assertActor($user);
        $deviceId = (string) $this->validDevice($deviceId, required: true);

        return $this->locked($callId, function (Call $call) use ($user, $deviceId): Call {
            $p = $this->participantOrFail($call, $user->id);

            if ($p->state->isIn()) {
                if ($p->device_id === null) {
                    $p->forceFill(['device_id' => $deviceId])->save(); // quien inicia fija aquí su dispositivo

                    return $call;
                }
                if ($p->device_id === $deviceId) {
                    return $call; // reintento del mismo dispositivo: misma respuesta
                }
                throw CallException::answeredElsewhere();
            }
            if (! $call->status->isLive()) {
                throw CallException::invalidTransition('accept on '.$call->status->value);
            }
            $this->moveParticipant($p, ParticipantState::Accepted, ['responded_at' => now(), 'device_id' => $deviceId]);
            $this->recorder->record($call, 'call.accepted', $user->id, $user->id, ['device_id' => $deviceId]);
            $this->recorder->notify($call, 'call.answered_elsewhere', $user->id, ['user_id' => $user->id], [$user->id], $deviceId);
            DB::afterCommit(fn () => $this->safely(fn () => $this->push->cancelIncomingCall([$user->id], $call->id, 'answered_elsewhere', $deviceId)));
            $this->presence->setInCall($user->id, $call->id, $this->connectSeconds());
            if ($call->answered_at === null) {
                $call->forceFill(['answered_at' => now()])->save();
            }
            $this->reconcile($call, $user->id);

            return $call;
        });
    }

    public function decline(User $user, string $callId): Call
    {
        $this->assertActor($user);

        return $this->locked($callId, function (Call $call) use ($user): Call {
            $p = $this->participantOrFail($call, $user->id);
            if ($p->state === ParticipantState::Declined) {
                return $call; // idempotente
            }
            if ($p->state !== ParticipantState::Ringing) {
                throw CallException::invalidTransition('decline from '.$p->state->value);
            }
            $this->moveParticipant($p, ParticipantState::Declined, ['responded_at' => now()]);
            $this->recorder->record($call, 'call.declined', $user->id, $user->id);
            DB::afterCommit(fn () => $this->safely(fn () => $this->push->cancelIncomingCall([$user->id], $call->id, 'declined')));
            $this->reconcile($call, $user->id);

            return $call;
        });
    }

    /** Quien inicia retira la llamada antes de que nadie conteste. */
    public function cancel(User $user, string $callId): Call
    {
        $this->assertActor($user);

        return $this->locked($callId, function (Call $call) use ($user): Call {
            $this->participantOrFail($call, $user->id);
            if ((int) $call->initiator_id !== (int) $user->id) {
                throw CallException::forbidden('Only the caller can cancel.');
            }
            if ($call->status === CallStatus::Cancelled) {
                return $call;
            }
            if (! in_array($call->status, [CallStatus::Initiating, CallStatus::Ringing], true)) {
                throw CallException::invalidTransition('cancel on '.$call->status->value);
            }
            $this->finish($call, CallStatus::Cancelled, 'cancelled_by_caller', $user->id);

            return $call;
        });
    }

    /**
     * Conectarse a la sala (tras contestar, o incorporarse a una llamada que sigue viva). Desde una
     * conversación, un miembro que no estaba invitado puede incorporarse a su llamada grupal.
     */
    public function join(User $user, string $callId, string $deviceId): Call
    {
        $this->assertActor($user);
        $deviceId = (string) $this->validDevice($deviceId, required: true);

        return $this->locked($callId, function (Call $call) use ($user, $deviceId): Call {
            if (! $call->status->isLive()) {
                throw CallException::invalidTransition('join on '.$call->status->value);
            }
            $p = $call->participantFor($user->id) ?? $this->admitConversationMember($call, $user);

            if ($p->state === ParticipantState::Joined) {
                if ($p->device_id === $deviceId) {
                    return $call;
                }
                throw CallException::answeredElsewhere();
            }
            if ($p->state === ParticipantState::Accepted && $p->device_id !== null && $p->device_id !== $deviceId) {
                throw CallException::answeredElsewhere();
            }
            if ($p->state === ParticipantState::Cancelled) {
                throw CallException::forbidden('The invitation was withdrawn.');
            }
            $wasRinging = $p->state === ParticipantState::Ringing;
            $this->moveParticipant($p, ParticipantState::Joined, ['joined_at' => now(), 'left_at' => null, 'device_id' => $deviceId,
                'responded_at' => $p->responded_at ?? now()]);
            if ($wasRinging) {
                $this->recorder->record($call, 'call.accepted', $user->id, $user->id, ['device_id' => $deviceId]);
                $this->recorder->notify($call, 'call.answered_elsewhere', $user->id, ['user_id' => $user->id], [$user->id], $deviceId);
                if ($call->answered_at === null && (int) $user->id !== (int) $call->initiator_id) {
                    $call->forceFill(['answered_at' => now()])->save();
                }
            }
            $this->recorder->record($call, 'call.participant.joined', $user->id, $user->id, ['device_id' => $deviceId]);
            $this->presence->setInCall($user->id, $call->id, 3600);
            $this->reconcile($call, $user->id);

            return $call;
        });
    }

    /** Salir (desde la sala o antes de conectarse). Sonando, equivale a rechazar. */
    public function leave(User $user, string $callId, ?string $deviceId = null): Call
    {
        $this->assertActor($user);
        $deviceId = $this->validDevice($deviceId, required: false);

        return $this->locked($callId, function (Call $call) use ($user, $deviceId): Call {
            $p = $this->participantOrFail($call, $user->id);
            if (! $call->status->isLive() || ! $p->state->isIn()) {
                if ($p->state === ParticipantState::Ringing && $call->status->isLive()) {
                    $this->moveParticipant($p, ParticipantState::Declined, ['responded_at' => now()]);
                    $this->recorder->record($call, 'call.declined', $user->id, $user->id);
                    $this->reconcile($call, $user->id);
                }

                return $call; // ya fuera: idempotente
            }
            if ($deviceId !== null && $p->device_id !== null && $p->device_id !== $deviceId) {
                return $call; // otro dispositivo de la misma persona no la saca de la llamada
            }
            $this->leaveParticipant($call, $p, $user->id);
            if ((int) $call->initiator_id === (int) $user->id && in_array($call->status, [CallStatus::Initiating, CallStatus::Ringing], true)
                && $call->participants->where('role', ParticipantRole::Member)->filter(fn ($m) => $m->state->isIn())->isEmpty()) {
                $this->finish($call, CallStatus::Cancelled, 'cancelled_by_caller', $user->id);

                return $call;
            }
            $this->reconcile($call, $user->id);

            return $call;
        });
    }

    /** Anfitrión: termina la llamada para todos. El resto de participantes, simplemente sale. */
    public function end(User $user, string $callId): Call
    {
        $this->assertActor($user);
        $call = $this->locked($callId, fn (Call $call) => $call);
        $this->participantOrFail($call, $user->id);
        if ((int) $call->initiator_id !== (int) $user->id) {
            return $this->leave($user, $callId);
        }

        return $this->locked($callId, function (Call $call) use ($user): Call {
            if (! $call->status->isLive()) {
                return $call; // idempotente
            }
            if (in_array($call->status, [CallStatus::Initiating, CallStatus::Ringing], true)) {
                $this->finish($call, CallStatus::Cancelled, 'cancelled_by_caller', $user->id);
            } else {
                $this->finish($call, CallStatus::Ended, 'ended_by_host', $user->id);
            }

            return $call;
        });
    }

    /** Fallo del sistema o de conexión (lo usará la integración de medios). */
    public function fail(string $callId, string $reason): Call
    {
        return $this->locked($callId, function (Call $call) use ($reason): Call {
            if ($call->status->isLive()) {
                $this->finish($call, CallStatus::Failed, mb_substr($reason, 0, 32), null);
            }

            return $call;
        });
    }

    // ─── Medios ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Credenciales de la sala para el dispositivo con el que la persona está en la llamada. El backend
     * decide los permisos; nada de esto se guarda.
     */
    public function credentials(User $user, string $callId, string $deviceId): JoinCredentials
    {
        $this->assertActor($user);
        $deviceId = (string) $this->validDevice($deviceId, required: true);
        $call = $this->locked($callId, fn (Call $call) => $call);
        $p = $this->participantOrFail($call, $user->id);
        if (! $call->status->isLive() || ! $p->state->isIn()) {
            throw CallException::forbidden('Not in the call.');
        }
        if ($p->device_id !== null && $p->device_id !== $deviceId) {
            throw CallException::answeredElsewhere();
        }

        return $this->media->issueJoinCredentials(new MediaGrant(
            room: $call->room_name,
            identity: 'u'.$user->id.'.'.$deviceId,
            userId: $user->id,
            deviceId: $deviceId,
            canPublishVideo: $call->media === CallMedia::Video,
            canPublishAudio: true,
            canShareScreen: true,
            isHost: $p->role === ParticipantRole::Host,
            ttlSeconds: max(60, (int) config('teamcalls.join_token_ttl', 300)),
        ));
    }

    /**
     * Evento EXTERNO del servidor de medios (webhook): conexión o desconexión confirmada de una
     * identidad. Idempotente por $eventId: un evento repetido no se aplica dos veces.
     */
    public function applyMediaEvent(string $callId, string $eventId, string $kind, int $userId, string $deviceId): Call
    {
        if (! in_array($kind, ['joined', 'left'], true) || preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $eventId) !== 1) {
            throw CallException::invalid('Invalid media event.');
        }
        $deviceId = (string) $this->validDevice($deviceId, required: true);

        return $this->locked($callId, function (Call $call) use ($eventId, $kind, $userId, $deviceId): Call {
            $key = 'media:'.$eventId;
            if (CallEvent::query()->where('call_id', $call->id)->where('dedupe_key', $key)->exists()) {
                return $call; // repetido
            }
            $p = $call->participantFor($userId);
            $applied = false;
            if ($p !== null && $call->status->isLive()) {
                if ($kind === 'joined' && CallStateMachine::canParticipant($p->state, ParticipantState::Joined)
                    && ($p->device_id === null || $p->device_id === $deviceId)) {
                    $this->moveParticipant($p, ParticipantState::Joined, ['joined_at' => now(), 'left_at' => null, 'device_id' => $deviceId,
                        'responded_at' => $p->responded_at ?? now()]);
                    $applied = true;
                } elseif ($kind === 'left' && $p->state->isIn() && $p->device_id === $deviceId) {
                    $this->leaveParticipant($call, $p, null);
                    $applied = true;
                }
            }
            $this->recorder->record($call, 'call.media.'.$kind, null, $userId, ['device_id' => $deviceId, 'applied' => $applied], [], null, $key);
            if ($applied) {
                $this->reconcile($call, null);
            }

            return $call;
        });
    }

    // ─── Consultas ──────────────────────────────────────────────────────────────────────────────

    public function find(User $viewer, string $callId): CallSummary
    {
        $this->assertActor($viewer);
        $call = $this->locked($callId, fn (Call $call) => $call);
        $this->participantOrFail($call, $viewer->id);

        return CallSummary::from($call, $viewer->id);
    }

    /** @return list<CallSummary> llamadas en las que participa la persona, recientes primero */
    public function history(User $user, int $limit = 20): array
    {
        $this->assertActor($user);
        $this->expireStaleFor($user);

        return Call::query()
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->with('participants')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn (Call $c) => CallSummary::from($c, $user->id))
            ->all();
    }

    /** @return list<CallSummary> llamadas que la persona no contestó */
    public function missed(User $user, int $limit = 20): array
    {
        $this->assertActor($user);
        $this->expireStaleFor($user);

        return Call::query()
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id)->where('state', ParticipantState::Missed->value))
            ->with('participants')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn (Call $c) => CallSummary::from($c, $user->id))
            ->all();
    }

    /** Llamada viva más reciente de una conversación (para mostrar «unirse» en TeamChat). */
    public function activeForConversation(User $viewer, string $type, string $id): ?Call
    {
        $this->assertActor($viewer);
        $ref = new ConversationRef($type, $id);
        if ($this->conversations->institutionOf($ref) !== (int) $viewer->institution_id || ! $this->conversations->isMember($viewer->id, $ref)) {
            throw CallException::forbidden('Not a member of the conversation.');
        }
        $callId = Call::query()->where('context_type', $type)->where('context_id', $id)
            ->whereIn('status', array_map(fn (CallStatus $s) => $s->value, CallStatus::live()))
            ->orderByDesc('created_at')->value('id');
        if ($callId === null) {
            return null;
        }
        $call = $this->locked((string) $callId, fn (Call $call) => $call);

        return $call->status->isLive() ? $call : null;
    }

    /**
     * Aplica lo vencido (invitaciones sin contestar, conexiones que no llegan) en todas las
     * instituciones. Lo ejecuta el comando programado; cada llamada en el contexto de SU institución.
     */
    public function expireStale(int $limit = 200): int
    {
        /** @var list<array{id:string, institution_id:int}> $rows */
        $rows = $this->context->runGlobally(fn () => $this->staleQuery()->limit($limit)->get(['id', 'institution_id'])
            ->map(fn (Call $c) => ['id' => (string) $c->id, 'institution_id' => (int) $c->institution_id])->all());

        $n = 0;
        foreach ($rows as $row) {
            try {
                $this->context->runFor($row['institution_id'], fn () => $this->locked($row['id'], fn (Call $call) => $call));
                $n++;
            } catch (Throwable $e) {
                Log::warning('teamcalls.expire_failed', ['call_id' => $row['id'], 'error' => class_basename($e)]);
            }
        }

        return $n;
    }

    // ─── Reglas internas ────────────────────────────────────────────────────────────────────────

    /**
     * Ejecuta $fn con la llamada y sus participantes bloqueados, tras aplicar lo vencido.
     *
     * @template T
     *
     * @param  callable(Call): T  $fn
     * @return T
     */
    private function locked(string $callId, callable $fn): mixed
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $callId) !== 1) {
            throw CallException::notFound();
        }

        return DB::transaction(function () use ($callId, $fn) {
            $call = Call::query()->whereKey($callId)->lockForUpdate()->first() ?? throw CallException::notFound();
            $call->setRelation('participants', CallParticipant::query()->where('call_id', $call->id)->orderBy('id')->lockForUpdate()->get());
            $this->settle($call);

            return $fn($call);
        });
    }

    /** Lo vencido: invitaciones sin contestar → perdidas; conexión que no llega → fallida. */
    private function settle(Call $call): void
    {
        if (! $call->status->isLive()) {
            return;
        }
        $now = now();
        $expired = [];
        foreach ($call->participants as $p) {
            if ($p->state === ParticipantState::Ringing && $p->ring_expires_at !== null && $p->ring_expires_at->lte($now)) {
                $this->moveParticipant($p, ParticipantState::Missed);
                $this->recorder->record($call, 'call.missed', null, $p->user_id);
                $expired[] = $p->user_id;
            }
        }
        if ($expired !== []) {
            DB::afterCommit(fn () => $this->safely(fn () => $this->push->cancelIncomingCall($expired, $call->id, 'missed')));
        }
        if ($call->status === CallStatus::Connecting && $call->answered_at !== null
            && $call->answered_at->lte($now->copy()->subSeconds($this->connectSeconds()))
            && $call->participants->where('state', ParticipantState::Joined)->count() < 2) {
            $this->finish($call, CallStatus::Failed, 'connect_timeout', null);

            return;
        }
        $this->reconcile($call, null);
    }

    /** Estado global a partir de los individuales. Una persona que rechaza no termina una llamada grupal. */
    private function reconcile(Call $call, ?int $actorId): void
    {
        if (! $call->status->isLive()) {
            return;
        }
        $in = $call->participants->filter(fn (CallParticipant $p) => $p->state->isIn());
        $membersIn = $in->filter(fn (CallParticipant $p) => $p->role === ParticipantRole::Member);
        $joined = $call->participants->where('state', ParticipantState::Joined)->count();
        $ringing = $call->participants->where('state', ParticipantState::Ringing)->count();

        if (in_array($call->status, [CallStatus::Initiating, CallStatus::Ringing], true)) {
            if ($membersIn->isNotEmpty()) {
                $this->moveCall($call, CallStatus::Connecting);
            } elseif ($ringing === 0) {
                $members = $call->participants->where('role', ParticipantRole::Member);
                $allDeclined = $members->isNotEmpty() && $members->every(fn (CallParticipant $p) => $p->state === ParticipantState::Declined);
                $this->finish($call, $allDeclined ? CallStatus::Declined : CallStatus::Missed, $allDeclined ? 'declined' : 'no_answer', $actorId);

                return;
            }
        }
        if ($call->status === CallStatus::Connecting && $joined >= 2) {
            $this->moveCall($call, CallStatus::Active);
            $call->forceFill(['connected_at' => now()])->save();
            $this->recorder->record($call, 'call.connected', $actorId);
        }
        if (in_array($call->status, [CallStatus::Connecting, CallStatus::Active], true) && $in->count() < 2 && $ringing === 0) {
            $this->finish($call, CallStatus::Ended, 'everyone_left', $actorId);
        }
    }

    /** Cierre global: estado terminal, cada persona a su estado final, avisos retirados y sala cerrada. */
    private function finish(Call $call, CallStatus $status, string $reason, ?int $actorId): void
    {
        CallStateMachine::call($call->status, $status);
        $ringing = [];
        foreach ($call->participants as $p) {
            if ($p->state->isIn()) {
                $this->moveParticipant($p, ParticipantState::Left, ['left_at' => now()]);
            } elseif ($p->state === ParticipantState::Ringing) {
                $this->moveParticipant($p, $status === CallStatus::Missed ? ParticipantState::Missed : ParticipantState::Cancelled);
                $ringing[] = $p->user_id;
            }
        }
        $call->forceFill(['status' => $status, 'ended_at' => now(), 'end_reason' => $reason])->save();
        $this->recorder->record($call, 'call.ended', $actorId, null, ['status' => $status->value, 'reason' => $reason]);
        foreach ($call->participants as $p) {
            $this->presence->clearInCall($p->user_id, $call->id);
        }
        $room = $call->room_name;
        DB::afterCommit(function () use ($ringing, $call, $status, $room): void {
            if ($ringing !== []) {
                $this->safely(fn () => $this->push->cancelIncomingCall($ringing, $call->id, $status->value));
            }
            $this->safely(fn () => $this->media->closeRoom($room));
        });
    }

    private function leaveParticipant(Call $call, CallParticipant $p, ?int $actorId): void
    {
        $identity = $p->device_id !== null ? 'u'.$p->user_id.'.'.$p->device_id : null;
        $this->moveParticipant($p, ParticipantState::Left, ['left_at' => now()]);
        $this->recorder->record($call, 'call.participant.left', $actorId, $p->user_id);
        $this->presence->clearInCall($p->user_id, $call->id);
        if ($identity !== null) {
            $room = $call->room_name;
            DB::afterCommit(fn () => $this->safely(fn () => $this->media->removeParticipant($room, $identity)));
        }
    }

    /** @param list<int> $userIds */
    private function ring(Call $call, int $actorId, array $userIds): void
    {
        foreach ($userIds as $id) {
            $this->recorder->record($call, 'call.ringing', $actorId, (int) $id, ['media' => $call->media->value], [(int) $id]);
        }
        $summary = CallSummary::from($call);
        DB::afterCommit(fn () => $this->safely(fn () => $this->push->incomingCall(array_map('intval', $userIds), $summary)));
    }

    private function addParticipant(Call $call, int $userId, ParticipantRole $role, ParticipantState $state, ?int $invitedBy, ?string $deviceId): CallParticipant
    {
        $now = now();
        $p = new CallParticipant;
        $p->forceFill([
            'institution_id' => $call->institution_id,
            'call_id' => $call->id,
            'user_id' => $userId,
            'role' => $role,
            'state' => $state,
            'invited_by' => $invitedBy,
            'invited_at' => $now,
            'ring_expires_at' => $state === ParticipantState::Ringing ? $now->copy()->addSeconds($this->ringSeconds()) : null,
            'responded_at' => $state === ParticipantState::Ringing ? null : $now,
            'device_id' => $deviceId,
        ])->save();

        return $p;
    }

    /** @param array<string,mixed> $attributes */
    private function moveParticipant(CallParticipant $p, ParticipantState $to, array $attributes = []): void
    {
        if (CallStateMachine::participant($p->state, $to)) {
            $p->forceFill(['state' => $to] + $attributes)->save();
        }
    }

    private function moveCall(Call $call, CallStatus $to): void
    {
        if (CallStateMachine::call($call->status, $to)) {
            $call->forceFill(['status' => $to])->save();
        }
    }

    /**
     * Destino → [alcance, conversación, invitados]. Los invitados salen del SERVIDOR: miembros de la
     * conversación o personas validadas una a una.
     *
     * @return array{0: CallScope, 1: ConversationRef|null, 2: Collection<int, User>}
     */
    private function resolveTarget(User $initiator, CallTarget $target): array
    {
        if ($target->conversation !== null) {
            $ref = $target->conversation;
            if ($this->conversations->institutionOf($ref) !== (int) $initiator->institution_id || ! $this->conversations->canStartCall($initiator, $ref)) {
                throw CallException::forbidden('Not allowed to call from this conversation.');
            }
            $ids = array_values(array_diff($this->conversations->memberIds($ref), [$initiator->id]));
            $invitees = $this->loadUsers($ids)->filter(fn (User $u) => $this->users->canContact($initiator, $u))->values();
            if ($invitees->isEmpty()) {
                throw CallException::invalid('Nobody in the conversation can receive the call.');
            }
        } else {
            $ref = null;
            $ids = array_values(array_diff($target->userIds, [$initiator->id]));
            if ($ids === []) {
                throw CallException::invalid('You cannot call yourself.');
            }
            $invitees = $this->loadUsers($ids);
            // Todas o ninguna: no se revela cuál de las personas no es válida.
            if ($invitees->count() !== count($ids) || $invitees->contains(fn (User $u) => ! $this->users->canContact($initiator, $u))) {
                throw CallException::forbidden('Some of the people cannot be called.');
            }
        }

        return [$invitees->count() === 1 ? CallScope::Direct : CallScope::Group, $ref, $invitees];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function authorizedInvitees(User $actor, Call $call, array $ids): Collection
    {
        $ids = array_values(array_diff($ids, [$actor->id]));
        $users = $this->loadUsers($ids);
        $ref = $call->context_type !== null && $call->context_id !== null
            ? new ConversationRef($call->context_type, $call->context_id) : null;
        $ok = $users->count() === count($ids) && $users->every(fn (User $u) => $this->users->canContact($actor, $u)
            && ($ref === null || $this->conversations->isMember($u->id, $ref)));
        if (! $ok) {
            throw CallException::forbidden('Some of the people cannot be invited.');
        }

        return $users;
    }

    /** Miembro de la conversación de origen que se incorpora sin invitación previa. */
    private function admitConversationMember(Call $call, User $user): CallParticipant
    {
        $ref = $call->context_type !== null && $call->context_id !== null
            ? new ConversationRef($call->context_type, $call->context_id) : null;
        if ($ref === null || ! $this->conversations->isMember($user->id, $ref) || ! $this->users->canUseCalls($user)) {
            throw CallException::notFound();
        }
        if ($call->participants->count() + 1 > $this->maxParticipants()) {
            throw CallException::tooManyParticipants($this->maxParticipants());
        }
        $p = $this->addParticipant($call, $user->id, ParticipantRole::Member, ParticipantState::Left, null, null);
        $p->forceFill(['responded_at' => null])->save();
        $call->load('participants');

        return $call->participantFor($user->id) ?? throw CallException::notFound();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function loadUsers(array $ids): Collection
    {
        return $ids === [] ? collect() : User::query()->whereKey($ids)->get();
    }

    private function participantOrFail(Call $call, int $userId): CallParticipant
    {
        // Quien no participa no distingue una llamada ajena de una inexistente.
        return $call->participantFor($userId) ?? throw CallException::notFound();
    }

    /** ¿Está ya en otra llamada viva (contestada o conectada, o como quien la inició)? */
    private function isBusy(int $userId): bool
    {
        return CallParticipant::query()->where('user_id', $userId)
            ->whereIn('state', [ParticipantState::Accepted->value, ParticipantState::Joined->value])
            ->whereHas('call', fn ($q) => $q->whereIn('status', array_map(fn (CallStatus $s) => $s->value, CallStatus::live())))
            ->exists();
    }

    private function byIdempotency(User $initiator, string $key): ?Call
    {
        return Call::query()->where('initiator_id', $initiator->id)->where('idempotency_key', $key)->first();
    }

    private function sameRequest(Call $existing, string $hash): Call
    {
        if (! hash_equals($existing->request_hash, $hash)) {
            throw CallException::idempotencyConflict();
        }

        return $this->locked($existing->id, fn (Call $call) => $call);
    }

    private function assertActor(User $user): void
    {
        if (! $this->users->canUseCalls($user)) {
            throw CallException::forbidden('The user cannot use calls.');
        }
        if (! $this->context->has() || $this->context->id() !== (int) $user->institution_id) {
            throw CallException::forbidden('Institution mismatch.');
        }
    }

    private function validKey(string $key): string
    {
        if (preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $key) !== 1) {
            throw CallException::invalid('Invalid idempotency key.');
        }

        return $key;
    }

    private function validDevice(?string $deviceId, bool $required): ?string
    {
        if ($deviceId === null || $deviceId === '') {
            if ($required) {
                throw CallException::invalid('A device id is required.');
            }

            return null;
        }
        if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $deviceId) !== 1) {
            throw CallException::invalid('Invalid device id.');
        }

        return $deviceId;
    }

    /** @return Builder<Call> */
    private function staleQuery(): Builder
    {
        $now = now();

        return Call::query()
            ->whereIn('status', array_map(fn (CallStatus $s) => $s->value, CallStatus::live()))
            ->where(fn ($q) => $q
                ->whereHas('participants', fn ($p) => $p->where('state', ParticipantState::Ringing->value)->where('ring_expires_at', '<=', $now))
                ->orWhere(fn ($c) => $c->where('status', CallStatus::Connecting->value)->where('answered_at', '<=', $now->copy()->subSeconds($this->connectSeconds()))));
    }

    private function expireStaleFor(User $user): void
    {
        $ids = $this->staleQuery()->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))->limit(50)->pluck('id');
        foreach ($ids as $id) {
            $this->locked((string) $id, fn (Call $call) => $call);
        }
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning('teamcalls.side_effect_failed', ['error' => class_basename($e)]);
        }
    }

    private function ringSeconds(): int
    {
        return max(5, (int) config('teamcalls.ring_seconds', 45));
    }

    private function connectSeconds(): int
    {
        return max(10, (int) config('teamcalls.connect_seconds', 90));
    }

    private function maxParticipants(): int
    {
        return max(2, (int) config('teamcalls.max_participants', 25));
    }
}
