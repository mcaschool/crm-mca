<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
use Modules\TeamCalls\Enums\MediaState;
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
use Modules\TeamCalls\Support\MediaIdentity;
use Modules\TeamCalls\Support\MediaParticipant;
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
                // Serializa los inicios de una misma persona y repite las comprobaciones con lecturas
                // bloqueantes: dos inicios simultáneos con claves distintas no pueden pasar ambos el
                // «ocupado», y dos con la misma clave devuelven la misma llamada.
                User::query()->whereKey($initiator->id)->lockForUpdate()->first();
                $existing = $this->byIdempotency($initiator, $key, lock: true);
                if ($existing !== null) {
                    return $this->sameRequest($existing, $hash);
                }
                if ($this->isBusy($initiator->id, lock: true)) {
                    throw CallException::busy();
                }

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
                    'room_name' => $this->newRoomName(),
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
                    // Volver a invitar a alguien expulsado es una decisión explícita: retira la expulsión.
                    $p->forceFill(['state' => ParticipantState::Ringing, 'invited_by' => $actor->id, 'invited_at' => now(),
                        'ring_expires_at' => now()->addSeconds($this->ringSeconds()), 'responded_at' => null, 'device_id' => null,
                        'media_state' => null, 'media_session' => null, 'revoked_at' => null, 'revoked_by' => null])->save();
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
        // Sin servidor de medios nadie se conecta: la llamada nunca figura como activa sin sala real.
        if (! $this->media->available()) {
            throw CallException::mediaUnavailable();
        }

        return $this->locked($callId, function (Call $call) use ($user, $deviceId): Call {
            if (! $call->status->isLive()) {
                throw CallException::invalidTransition('join on '.$call->status->value);
            }
            $p = $call->participantFor($user->id) ?? $this->admitConversationMember($call, $user);
            if ($p->revoked_at !== null) {
                throw CallException::forbidden('The participant was removed from the call.');
            }

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
            // Con un servidor de medios que confirma conexiones, unirse es «conectándose»: la conexión
            // real (y con ella la llamada activa) solo llega con su evento firmado.
            $confirms = $this->media->confirmsConnections();
            $this->moveParticipant($p, ParticipantState::Joined, ['joined_at' => now(), 'left_at' => null, 'device_id' => $deviceId,
                'responded_at' => $p->responded_at ?? now()]
                + ($confirms ? ['media_state' => MediaState::Connecting, 'media_session' => null] : []));
            if ($wasRinging) {
                $this->recorder->record($call, 'call.accepted', $user->id, $user->id, ['device_id' => $deviceId]);
                $this->recorder->notify($call, 'call.answered_elsewhere', $user->id, ['user_id' => $user->id], [$user->id], $deviceId);
                if ($call->answered_at === null && (int) $user->id !== (int) $call->initiator_id) {
                    $call->forceFill(['answered_at' => now()])->save();
                }
            }
            $this->recorder->record($call, $confirms ? 'call.participant.connecting' : 'call.participant.joined', $user->id, $user->id, ['device_id' => $deviceId]);
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

    /**
     * Sale de todas sus llamadas vivas en las que está con ESE dispositivo (p. ej. al cerrar la sesión web
     * que lo identificaba). Devuelve de cuántas salió.
     */
    public function leaveDevice(User $user, string $deviceId): int
    {
        $ids = CallParticipant::query()->where('user_id', $user->id)->where('device_id', $deviceId)
            ->whereIn('state', [ParticipantState::Accepted->value, ParticipantState::Joined->value])
            ->whereHas('call', fn ($q) => $q->whereIn('status', array_map(fn (CallStatus $s) => $s->value, CallStatus::live())))
            ->limit(20)->pluck('call_id');
        $n = 0;
        foreach ($ids as $callId) {
            try {
                $this->leave($user, (string) $callId, $deviceId);
                $n++;
            } catch (CallException) {
                // ya no está o no tiene acceso: nada que hacer
            }
        }

        return $n;
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
     * decide la sala (opaca, la de ESTA llamada), la identidad y los permisos; nada de esto se guarda.
     * Las credenciales no son de un solo uso: la revocación se hace cumplir también en los eventos del
     * servidor de medios (ver applyMediaEvent) y con teamcalls:media-sweep.
     */
    public function credentials(User $user, string $callId, string $deviceId): JoinCredentials
    {
        $this->assertActor($user);
        $deviceId = (string) $this->validDevice($deviceId, required: true);
        if (! $this->media->available()) {
            throw CallException::mediaUnavailable();
        }
        $call = $this->locked($callId, function (Call $call) use ($user, $deviceId): Call {
            $p = $this->participantOrFail($call, $user->id);
            if ($p->revoked_at !== null) {
                throw CallException::forbidden('The participant was removed from the call.');
            }
            if (! $call->status->isLive() || ! $p->state->isIn()) {
                throw CallException::forbidden('Not in the call.');
            }
            if ($p->device_id !== null && $p->device_id !== $deviceId) {
                throw CallException::answeredElsewhere();
            }
            if ($p->device_id === null) {
                $p->forceFill(['device_id' => $deviceId])->save(); // la credencial fija el dispositivo
            }

            return $call;
        });
        $p = $this->participantOrFail($call, $user->id);

        // Fuera de la transacción: la sala la crea SOLO el backend (idempotente).
        $this->media->prepareRoom($call->room_name, $this->maxParticipants());

        return $this->media->issueJoinCredentials($this->grantFor($call, $p, $deviceId));
    }

    /**
     * Quien inicia la llamada expulsa a un participante: deja de recibir credenciales, sale de la
     * llamada y se le desconecta del servidor de medios. Volver a invitarle lo readmite.
     *
     * LiveKit autohospedado NO invalida credenciales ya emitidas (verificado con 1.13.10, tampoco las
     * renovadas por el propio servidor, que llevan los permisos concedidos). Por eso, si la llamada
     * sigue con medios, se ROTA la sala: los demás pasan a una sala nueva y la anterior se cierra; con
     * room.auto_create=false ninguna credencial de la sala vieja vuelve a servir.
     */
    public function revoke(User $actor, string $callId, int $userId): Call
    {
        $this->assertActor($actor);

        return $this->locked($callId, function (Call $call) use ($actor, $userId): Call {
            $this->participantOrFail($call, $actor->id);
            if ((int) $call->initiator_id !== (int) $actor->id) {
                throw CallException::forbidden('Only the host can remove participants.');
            }
            if ($userId === (int) $actor->id) {
                throw CallException::invalid('The host cannot remove themselves.');
            }
            $p = $this->participantOrFail($call, $userId);
            if ($p->revoked_at !== null) {
                return $call; // idempotente
            }
            if (! $call->status->isLive()) {
                throw CallException::invalidTransition('revoke on '.$call->status->value);
            }
            $this->expel($call, $p, $actor->id, 'revoked');

            return $call;
        });
    }

    /**
     * Retira el acceso de una persona a TODAS sus llamadas vivas de la institución activa (contrato para
     * CollaborationCore: usuario desactivado o permiso de colaboración retirado). Mismo efecto que una
     * expulsión, sin actor. Devuelve en cuántas llamadas estaba.
     */
    public function revokeAccess(int $userId, string $reason = 'access_revoked'): int
    {
        $reason = preg_match('/^[a-z_]{1,32}$/', $reason) === 1 ? $reason : 'access_revoked';
        $ids = Call::query()->whereIn('status', array_map(fn (CallStatus $s) => $s->value, CallStatus::live()))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId)->whereNull('revoked_at'))
            ->limit(100)->pluck('id');
        $n = 0;
        foreach ($ids as $id) {
            $this->locked((string) $id, function (Call $call) use ($userId, $reason, &$n): void {
                $p = $call->participantFor($userId);
                if ($p !== null && $p->revoked_at === null && $call->status->isLive()) {
                    $this->expel($call, $p, null, $reason);
                    $n++;
                }
            });
        }

        return $n;
    }

    /**
     * Evento EXTERNO del servidor de medios (webhook verificado): conexión o desconexión confirmada de
     * una identidad. Idempotente por $eventId. $session (sid de la conexión) ordena los eventos:
     *
     *  - Conexión: solo cuenta para quien está en la llamada (aceptada o dentro), sin expulsión, con el
     *    MISMO dispositivo de la llamada y con permiso vigente. Cualquier otra conexión (otra persona,
     *    otra institución, otro dispositivo, alguien expulsado, una llamada terminada o una sesión que
     *    ya se cerró) se rechaza y se DESCONECTA del servidor de medios: así una credencial todavía
     *    vigente no sirve para volver.
     *  - Desconexión: solo de la sesión vigente; la de una sesión anterior (llega tarde) se ignora.
     *  - Nada de esto reactiva una llamada terminada.
     */
    public function applyMediaEvent(string $callId, string $eventId, string $kind, int $userId, string $deviceId, ?string $session = null): Call
    {
        if (! in_array($kind, ['joined', 'left'], true) || preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $eventId) !== 1
            || ($session !== null && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $session) !== 1)) {
            throw CallException::invalid('Invalid media event.');
        }
        $deviceId = (string) $this->validDevice($deviceId, required: true);

        return $this->locked($callId, function (Call $call) use ($eventId, $kind, $userId, $deviceId, $session): Call {
            $key = 'media:'.$eventId;
            if (CallEvent::query()->where('call_id', $call->id)->where('dedupe_key', $key)->exists()) {
                return $call; // repetido
            }
            $p = $call->participantFor($userId);
            $rejected = false;
            if ($kind === 'joined') {
                [$applied, $rejected] = $this->mediaJoined($call, $p, $userId, $deviceId, $session);
            } else {
                $applied = $this->mediaLeft($call, $p, $deviceId, $session);
            }
            $this->recorder->record($call, 'call.media.'.$kind, null, $userId,
                ['device_id' => $deviceId, 'session' => $session, 'applied' => $applied] + ($rejected ? ['rejected' => true] : []),
                $applied ? null : [], null, $key);
            if ($rejected) {
                $this->disconnect($call, MediaIdentity::for($userId, $deviceId));
            }
            if ($applied) {
                $this->reconcile($call, null);
            }

            return $call;
        });
    }

    /** La sala se cerró en el servidor de medios: quien seguía conectado queda desconectado. */
    public function applyRoomFinished(string $callId, string $eventId): Call
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $eventId) !== 1) {
            throw CallException::invalid('Invalid media event.');
        }

        return $this->locked($callId, function (Call $call) use ($eventId): Call {
            $key = 'media:'.$eventId;
            if (CallEvent::query()->where('call_id', $call->id)->where('dedupe_key', $key)->exists() || ! $call->status->isLive()) {
                return $call;
            }
            $gone = $call->participants->filter(fn (CallParticipant $p) => $p->state->isIn() && $p->media_state === MediaState::Connected);
            foreach ($gone as $p) {
                $this->leaveParticipant($call, $p, null, fromMediaServer: true);
            }
            $this->recorder->record($call, 'call.media.room_finished', null, null, ['participants' => $gone->count()], [], null, $key);
            $this->reconcile($call, null);

            return $call;
        });
    }

    /**
     * Participantes REALES de la sala según el servidor de medios, para quien está en la llamada. No
     * revela dispositivos ni identidades ajenas: solo a qué participante corresponde cada conexión y si
     * está autorizada.
     *
     * @return list<array{user_id: int|null, authorized: bool, connected_at: string|null}>
     */
    public function mediaParticipants(User $viewer, string $callId): array
    {
        $this->assertActor($viewer);
        $call = $this->locked($callId, fn (Call $call) => $call);
        $this->participantOrFail($call, $viewer->id);
        if (! $call->status->isLive()) {
            return [];
        }

        return array_map(function (MediaParticipant $m) use ($call): array {
            $identity = MediaIdentity::parse($m->identity);
            $p = $identity !== null ? $call->participantFor($identity->userId) : null;

            return [
                'user_id' => $p?->user_id,
                'authorized' => $identity !== null && $this->mayBeInRoom($call, $p, $identity->userId, $identity->deviceId),
                'connected_at' => $m->joinedAt?->toIso8601String(),
            ];
        }, $this->media->participants($call->room_name));
    }

    /**
     * Contrasta la sala real con la base de datos (por si se perdió algún evento): desconecta a quien no
     * debería estar y da por desconectado a quien la base de datos cree conectado pero ya no está.
     * Una llamada terminada cierra su sala. Devuelve cuántas correcciones hizo.
     */
    public function reconcileMedia(string $callId): int
    {
        $call = $this->locked($callId, fn (Call $call) => $call);
        if (! $call->status->isLive()) {
            $this->safely(fn () => $this->media->closeRoom($call->room_name));

            return 0;
        }
        $listedAt = now();
        $real = $this->media->participants($call->room_name);
        $fixes = 0;
        $sessions = [];
        $found = [];
        foreach ($real as $m) {
            $sessions[] = $m->session;
            $identity = MediaIdentity::parse($m->identity);
            $p = $identity !== null ? $call->participantFor($identity->userId) : null;
            if ($identity === null || ! $this->mayBeInRoom($call, $p, $identity->userId, $identity->deviceId)) {
                $this->safely(fn () => $this->media->removeParticipant($call->room_name, $m->identity));
                $fixes++;
            } elseif ($p !== null && $m->session !== '' && ($p->media_state !== MediaState::Connected || $p->media_session !== $m->session)) {
                $found[] = [$identity, $m->session]; // conectado de verdad, pero su evento se perdió (lo admite applyMediaEvent)
            } elseif ($p !== null && ! $m->admitted) {
                $this->safely(fn () => $this->media->admitParticipant($this->grantFor($call, $p, $identity->deviceId))); // falló la concesión
                $fixes++;
            }
        }
        foreach ($found as [$identity, $session]) {
            $this->applyMediaEvent($call->id, 'sync.joined.'.$session, 'joined', $identity->userId, $identity->deviceId, $session);
            $fixes++;
        }
        foreach ($call->participants as $p) {
            // Margen: una conexión confirmada justo después de listar la sala no se da por perdida.
            if ($p->media_state === MediaState::Connected && $p->media_session !== null && $p->device_id !== null
                && ! in_array($p->media_session, $sessions, true) && $p->media_connected_at?->lte($listedAt->copy()->subSeconds(60))) {
                $this->applyMediaEvent($call->id, 'sync.left.'.$p->media_session, 'left', $p->user_id, $p->device_id, $p->media_session);
                $fixes++;
            }
        }

        return $fixes;
    }

    // ─── Consultas ──────────────────────────────────────────────────────────────────────────────

    /**
     * Lectura de una llamada. Sin bloquear filas salvo que haya algo vencido que aplicar (el sondeo de la
     * interfaz no debe competir con los webhooks ni con las operaciones).
     */
    public function find(User $viewer, string $callId): CallSummary
    {
        $this->assertActor($viewer);
        $call = $this->readable($callId);
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

    /**
     * Llamadas VIVAS en las que la persona sigue implicada (suena, aceptada o dentro), con lo vencido ya
     * aplicado. La consulta la interfaz web por sondeo hasta que CollaborationRealtime entregue eventos.
     *
     * @return list<CallSummary>
     */
    public function live(User $user): array
    {
        $this->assertActor($user);
        $states = [ParticipantState::Ringing->value, ParticipantState::Accepted->value, ParticipantState::Joined->value];

        // Sin la consulta global de vencidos: solo se bloquea (y se aplica lo vencido) en la llamada que lo necesite.
        return Call::query()
            ->whereIn('status', array_map(fn (CallStatus $s) => $s->value, CallStatus::live()))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id)->whereIn('state', $states)->whereNull('revoked_at'))
            ->with('participants')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Call $c) => $this->needsSettle($c) ? $this->locked((string) $c->id, fn (Call $locked) => $locked) : $c)
            ->filter(function (Call $c) use ($user, $states): bool {
                $p = $c->participantFor($user->id);

                return $c->status->isLive() && $p !== null && $p->revoked_at === null && in_array($p->state->value, $states, true);
            })
            ->map(fn (Call $c) => CallSummary::from($c, $user->id))
            ->values()
            ->all();
    }

    /**
     * Personas a las que se puede llamar (mismas reglas que al iniciar: UserDirectory::canContact), solo
     * de la institución de quien consulta y solo nombre e id. Lo sustituirá el directorio de CollaborationCore.
     *
     * @return list<array{id: int, name: string}>
     */
    public function contacts(User $viewer, ?string $search = null, int $limit = 200): array
    {
        $this->assertActor($viewer);
        $search = $search !== null ? trim(mb_substr($search, 0, 80)) : '';

        return User::query()
            ->where('institution_id', $viewer->institution_id)
            ->whereKeyNot($viewer->id)
            ->where('status', 'active')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->limit(max(1, min(500, $limit)))
            ->get()
            ->filter(fn (User $u) => $this->users->canContact($viewer, $u))
            ->map(fn (User $u) => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->values()
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
    /** La llamada para leer: sin bloqueo si no hay nada vencido; con bloqueo y aplicado si lo hay. */
    private function readable(string $callId): Call
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $callId) !== 1) {
            throw CallException::notFound();
        }
        $call = Call::query()->with('participants')->find($callId) ?? throw CallException::notFound();

        return $this->needsSettle($call) ? $this->locked($callId, fn (Call $locked) => $locked) : $call;
    }

    /** ¿Hay algo vencido que settle() aplicaría? (mismas condiciones, sin escribir nada) */
    private function needsSettle(Call $call): bool
    {
        if (! $call->status->isLive()) {
            return false;
        }
        $now = now();
        $grace = $now->copy()->subSeconds($this->reconnectGrace());
        foreach ($call->participants as $p) {
            if ($p->state === ParticipantState::Ringing && $p->ring_expires_at !== null && $p->ring_expires_at->lte($now)) {
                return true;
            }
            if ($p->state === ParticipantState::Joined && $p->media_state === MediaState::Disconnected
                && $p->media_disconnected_at?->lte($grace) && $this->media->confirmsConnections()) {
                return true;
            }
        }
        if ($call->status === CallStatus::Connecting && $call->answered_at !== null
            && $call->answered_at->lte($now->copy()->subSeconds($this->connectSeconds())) && $this->connectedCount($call) < 2) {
            return true;
        }

        return $this->mediaLost($call, $now);
    }

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
            && $this->connectedCount($call) < 2) {
            $this->finish($call, CallStatus::Failed, 'connect_timeout', null);

            return;
        }
        $graceCutoff = $now->copy()->subSeconds($this->reconnectGrace());
        foreach ($call->participants as $p) {
            if ($p->state === ParticipantState::Joined && $p->media_state === MediaState::Disconnected
                && $p->media_disconnected_at?->lte($graceCutoff) && $this->media->confirmsConnections()) {
                $this->leaveParticipant($call, $p, null, fromMediaServer: true); // no volvió a tiempo
            }
        }
        if (! $call->status->isLive()) {
            return;
        }
        if ($this->mediaLost($call, $now)) {
            $this->finish($call, CallStatus::Ended, 'media_lost', null);

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
        $joined = $this->connectedCount($call);
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
                $this->moveParticipant($p, ParticipantState::Left, ['left_at' => now()] + $this->mediaGone($p));
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

    /** Sale de la llamada. Si lo confirma el propio servidor de medios, no hace falta desconectarlo. */
    private function leaveParticipant(Call $call, CallParticipant $p, ?int $actorId, bool $fromMediaServer = false): void
    {
        $identity = $p->device_id !== null && ! $fromMediaServer ? MediaIdentity::for($p->user_id, $p->device_id) : null;
        $this->moveParticipant($p, ParticipantState::Left, ['left_at' => now()] + $this->mediaGone($p));
        $this->recorder->record($call, 'call.participant.left', $actorId, $p->user_id);
        $this->presence->clearInCall($p->user_id, $call->id);
        if ($identity !== null) {
            $room = $call->room_name;
            DB::afterCommit(fn () => $this->safely(fn () => $this->media->removeParticipant($room, $identity)));
        }
    }

    /**
     * Conexión confirmada por el servidor de medios.
     *
     * @return array{0: bool, 1: bool} [aplicada, rechazada (hay que desconectarla)]
     */
    private function mediaJoined(Call $call, ?CallParticipant $p, int $userId, string $deviceId, ?string $session): array
    {
        if (! $this->mayBeInRoom($call, $p, $userId, $deviceId) || $p === null
            || ($session !== null && $this->sessionEnded($call, $session))) {
            return [false, true];
        }
        if ($p->media_state === MediaState::Connected && $p->media_session === $session) {
            return [false, false]; // la misma conexión notificada otra vez
        }
        $wasJoined = $p->state === ParticipantState::Joined;
        $this->moveParticipant($p, ParticipantState::Joined, ['joined_at' => $p->joined_at ?? now(), 'left_at' => null,
            'responded_at' => $p->responded_at ?? now()]);
        $p->forceFill(['media_state' => MediaState::Connected, 'media_session' => $session, 'media_connected_at' => now(),
            'media_disconnected_at' => null])->save();
        if (! $wasJoined) {
            $this->recorder->record($call, 'call.participant.joined', null, $userId, ['device_id' => $deviceId]);
        }
        $this->admit($call, $p, $deviceId); // solo ahora recibe y envía medios

        return [true, false];
    }

    /** Desconexión confirmada: solo de la sesión vigente de quien sigue en la llamada con ese dispositivo. */
    private function mediaLeft(Call $call, ?CallParticipant $p, string $deviceId, ?string $session): bool
    {
        if (! $call->status->isLive() || $p === null || ! $p->state->isIn() || $p->device_id !== $deviceId) {
            return false;
        }
        if ($session !== null && $p->media_session !== null && $p->media_session !== $session) {
            return false; // desconexión de una sesión anterior que llega tarde
        }
        if ($this->media->confirmsConnections() && $p->state === ParticipantState::Joined) {
            // LiveKit avisa de la salida de la sesión vieja ANTES de la entrada de la nueva cuando alguien se
            // reconecta (verificado con 1.13.10): solo se marca desconectado; si no vuelve en el margen de
            // reconexión, settle() le saca de la llamada.
            $p->forceFill(['media_state' => MediaState::Disconnected, 'media_disconnected_at' => now()])->save();

            return true;
        }
        $this->leaveParticipant($call, $p, null, fromMediaServer: true);

        return true;
    }

    /**
     * ¿Puede esta identidad estar en la sala? Llamada viva, participante sin expulsión, dentro de la
     * llamada (aceptada o unida), con el MISMO dispositivo y con permiso vigente en su institución.
     */
    private function mayBeInRoom(Call $call, ?CallParticipant $p, int $userId, string $deviceId): bool
    {
        if (! $call->status->isLive() || $p === null || $p->revoked_at !== null || ! $p->state->isIn()
            || $p->device_id === null || $p->device_id !== $deviceId) {
            return false;
        }
        $user = User::query()->find($userId);

        return $user !== null && (int) $user->institution_id === (int) $call->institution_id && $this->users->canUseCalls($user);
    }

    /** ¿Ya se cerró esta sesión de medios? (una conexión que llega después de su desconexión) */
    private function sessionEnded(Call $call, string $session): bool
    {
        return CallEvent::query()->where('call_id', $call->id)->where('type', 'call.media.left')
            ->where('data->session', $session)->exists();
    }

    /** Desconecta una identidad del servidor de medios tras confirmar (y cierra la sala si la llamada terminó). */
    private function disconnect(Call $call, string $identity): void
    {
        $room = $call->room_name;
        $ended = ! $call->status->isLive();
        DB::afterCommit(function () use ($room, $identity, $ended): void {
            $this->safely(fn () => $this->media->removeParticipant($room, $identity));
            if ($ended) {
                $this->safely(fn () => $this->media->closeRoom($room));
            }
        });
    }

    /**
     * Personas realmente conectadas. Con un servidor de medios que confirma conexiones, solo cuentan las
     * confirmadas por él; aceptar o pedir unirse no basta.
     */
    private function connectedCount(Call $call): int
    {
        $joined = $call->participants->where('state', ParticipantState::Joined);
        if (! $this->media->confirmsConnections()) {
            return $joined->count();
        }

        return $joined->filter(fn (CallParticipant $p) => $p->media_state === MediaState::Connected)->count();
    }

    /** Expulsión (por quien inicia o por el sistema): sin credenciales nuevas, fuera de la sala y, si la llamada sigue, sala nueva. */
    private function expel(Call $call, CallParticipant $p, ?int $actorId, string $reason): void
    {
        $p->forceFill(['revoked_at' => now(), 'revoked_by' => $actorId])->save();
        if ($p->state->isIn()) {
            $this->leaveParticipant($call, $p, $actorId);
        } elseif ($p->state === ParticipantState::Ringing) {
            $this->moveParticipant($p, ParticipantState::Cancelled);
            $userId = $p->user_id;
            DB::afterCommit(fn () => $this->safely(fn () => $this->push->cancelIncomingCall([$userId], $call->id, 'revoked')));
        }
        $this->recorder->record($call, 'call.participant.revoked', $actorId, $p->user_id, ['reason' => $reason]);

        $members = $call->participants->where('role', ParticipantRole::Member);
        if (in_array($call->status, [CallStatus::Initiating, CallStatus::Ringing], true)
            && $members->every(fn (CallParticipant $m) => ! $m->state->isIn() && $m->state !== ParticipantState::Ringing)) {
            $this->finish($call, CallStatus::Cancelled, 'cancelled_by_caller', $actorId); // ya no queda a quién llamar

            return;
        }
        $this->reconcile($call, $actorId);
        if ($call->status->isLive() && $this->media->confirmsConnections()
            && $call->participants->contains(fn (CallParticipant $m) => $m->media_state !== null)) {
            $this->rotateRoom($call, $actorId, $reason);
        }
    }

    /**
     * Sala nueva para quienes siguen en la llamada; la anterior se cierra (desconecta a todos) y sus
     * credenciales, incluidas las renovadas por el servidor de medios, dejan de servir. Los demás
     * vuelven a pedir credenciales y a conectarse; la llamada no cambia de estado.
     */
    private function rotateRoom(Call $call, ?int $actorId, string $reason): void
    {
        $old = $call->room_name;
        $call->forceFill(['room_name' => $this->newRoomName()])->save();
        foreach ($call->participants as $m) {
            if ($m->media_state !== null && $m->media_state !== MediaState::Disconnected) {
                $m->forceFill(['media_state' => MediaState::Disconnected, 'media_session' => null, 'media_disconnected_at' => now()])->save();
            }
        }
        $this->recorder->record($call, 'call.media.room_rotated', $actorId, null, ['reason' => $reason]);
        DB::afterCommit(fn () => $this->safely(fn () => $this->media->closeRoom($old)));
    }

    /** Nombre de sala opaco: aleatorio, sin relación con el id de la llamada ni con la institución. */
    private function newRoomName(): string
    {
        return Call::ROOM_PREFIX.bin2hex(random_bytes(16));
    }

    /**
     * Llamada activa sin nadie conectado de verdad durante el tiempo de conexión (p. ej. tras rotar la
     * sala y que nadie vuelva): se da por terminada.
     */
    private function mediaLost(Call $call, \Illuminate\Support\Carbon $now): bool
    {
        if ($call->status !== CallStatus::Active || ! $this->media->confirmsConnections() || $this->connectedCount($call) > 0) {
            return false;
        }
        $last = $call->participants->map(fn (CallParticipant $p) => $p->media_disconnected_at?->getTimestamp() ?? 0)->max();

        return $last > 0 && $last <= $now->copy()->subSeconds($this->connectSeconds())->getTimestamp();
    }

    /** Lo que el backend autoriza a ESTA persona y dispositivo en la sala de la llamada. */
    private function grantFor(Call $call, CallParticipant $p, string $deviceId): MediaGrant
    {
        return new MediaGrant(
            room: $call->room_name,
            identity: MediaIdentity::for($p->user_id, $deviceId),
            userId: $p->user_id,
            deviceId: $deviceId,
            canPublishVideo: $call->media === CallMedia::Video,
            canPublishAudio: true,
            canShareScreen: $call->media === CallMedia::Video,
            isHost: $p->role === ParticipantRole::Host,
            ttlSeconds: max(60, min(3600, (int) config('teamcalls.join_token_ttl', 300))),
        );
    }

    /** Concede los medios a una conexión ya validada (tras confirmar la transacción). */
    private function admit(Call $call, CallParticipant $p, string $deviceId): void
    {
        $grant = $this->grantFor($call, $p, $deviceId);
        DB::afterCommit(fn () => $this->safely(fn () => $this->media->admitParticipant($grant)));
    }

    /** @return array<string, mixed> estado multimedia al salir de la llamada */
    private function mediaGone(CallParticipant $p): array
    {
        return $p->media_state !== null && $p->media_state !== MediaState::Disconnected
            ? ['media_state' => MediaState::Disconnected, 'media_disconnected_at' => now()] : [];
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
    private function isBusy(int $userId, bool $lock = false): bool
    {
        $query = CallParticipant::query()->where('user_id', $userId)
            ->whereIn('state', [ParticipantState::Accepted->value, ParticipantState::Joined->value])
            ->whereHas('call', fn ($q) => $q->whereIn('status', array_map(fn (CallStatus $s) => $s->value, CallStatus::live())));

        return $lock ? $query->sharedLock()->first(['id']) !== null : $query->exists();
    }

    private function byIdempotency(User $initiator, string $key, bool $lock = false): ?Call
    {
        $query = Call::query()->where('initiator_id', $initiator->id)->where('idempotency_key', $key);

        return ($lock ? $query->lockForUpdate() : $query)->first();
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
                ->orWhere(fn ($c) => $c->where('status', CallStatus::Connecting->value)->where('answered_at', '<=', $now->copy()->subSeconds($this->connectSeconds())))
                // Desconectados del servidor de medios que no volvieron en el margen de reconexión.
                ->orWhereHas('participants', fn ($p) => $p->where('state', ParticipantState::Joined->value)
                    ->where('media_state', MediaState::Disconnected->value)
                    ->where('media_disconnected_at', '<=', $now->copy()->subSeconds($this->reconnectGrace())))
                // Activa sin nadie conectado de verdad desde hace el tiempo de conexión (ver mediaLost).
                ->orWhere(fn ($c) => $c->where('status', CallStatus::Active->value)
                    ->whereDoesntHave('participants', fn ($p) => $p->where('media_state', MediaState::Connected->value))
                    ->whereHas('participants', fn ($p) => $p->where('media_disconnected_at', '<=', $now->copy()->subSeconds($this->connectSeconds())))));
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

    /** Segundos para reconectarse tras una desconexión del servidor de medios antes de salir de la llamada. */
    private function reconnectGrace(): int
    {
        return max(5, (int) config('teamcalls.reconnect_grace', 20));
    }

    private function maxParticipants(): int
    {
        return max(2, (int) config('teamcalls.max_participants', 25));
    }
}
