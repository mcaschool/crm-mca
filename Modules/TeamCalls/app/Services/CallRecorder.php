<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Services;

use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder;
use Modules\TeamCalls\Contracts\CallEventPublisher;
use Modules\TeamCalls\Events\CallAccepted;
use Modules\TeamCalls\Events\CallConnected;
use Modules\TeamCalls\Events\CallDeclined;
use Modules\TeamCalls\Events\CallDomainEvent;
use Modules\TeamCalls\Events\CallEnded;
use Modules\TeamCalls\Events\CallMissed;
use Modules\TeamCalls\Events\CallRinging;
use Modules\TeamCalls\Events\CallStarted;
use Modules\TeamCalls\Events\ParticipantJoined;
use Modules\TeamCalls\Events\ParticipantLeft;
use Modules\TeamCalls\Integration\Collaboration\CallEventEnvelopes;
use Modules\TeamCalls\Integration\Collaboration\OperationContext;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallEvent;
use Modules\TeamCalls\Support\CallEnvelope;
use Throwable;

/**
 * Registra cada hecho de una llamada en collab_call_events (dentro de la transacción), con versión
 * secuencial por llamada y correlación. Con CollaborationRealtime lo anota en SU outbox en la misma
 * transacción; sin él, lo publica tras confirmar por el puerto propio. El evento interno de Laravel se
 * emite tras confirmar. Un fallo de publicación posterior nunca deshace la operación.
 */
final class CallRecorder
{
    /** @var array<string, class-string<CallDomainEvent>> */
    private const DOMAIN_EVENTS = [
        'call.started' => CallStarted::class,
        'call.ringing' => CallRinging::class,
        'call.accepted' => CallAccepted::class,
        'call.declined' => CallDeclined::class,
        'call.missed' => CallMissed::class,
        'call.participant.joined' => ParticipantJoined::class,
        'call.participant.left' => ParticipantLeft::class,
        'call.connected' => CallConnected::class,
        'call.ended' => CallEnded::class,
    ];

    public function __construct(
        private readonly CallEventPublisher $publisher,
        private readonly OperationContext $operation,
        private readonly Container $app,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  list<int>|null  $recipients  null = todos los participantes de la llamada
     */
    public function record(Call $call, string $type, ?int $actorId, ?int $subjectId = null, array $data = [], ?array $recipients = null, ?string $exceptDeviceId = null, ?string $dedupeKey = null): CallEvent
    {
        $sequence = $this->nextSequence($call);
        $event = new CallEvent;
        $event->forceFill([
            'institution_id' => $call->institution_id,
            'call_id' => $call->id,
            'type' => $type,
            'sequence' => $sequence,
            'actor_id' => $actorId,
            'subject_id' => $subjectId,
            'data' => $data === [] ? null : $data,
            'dedupe_key' => $dedupeKey,
            'correlation_id' => $this->operation->correlationId(),
            'causation_id' => $this->operation->causationId(),
        ])->save();

        $payload = ($subjectId !== null ? ['user_id' => $subjectId] : []) + $data;
        $expires = $type === 'call.ringing' && $subjectId !== null ? $call->participantFor($subjectId)?->ring_expires_at?->toDateTimeImmutable() : null;
        $this->deliver($call, $event->id, $type, $sequence, $actorId, $payload, $recipients ?? $this->participantIds($call),
            $exceptDeviceId, $event->created_at, $expires);

        $class = self::DOMAIN_EVENTS[$type] ?? null;
        if ($class !== null) {
            DB::afterCommit(fn () => event(new $class($call->id, $call->institution_id, $actorId, $subjectId, $data, $event->id)));
        }

        return $event;
    }

    /**
     * Aviso solo en tiempo real, sin traza persistente (p. ej. call.answered_elsewhere a los demás
     * dispositivos de la misma persona). También ocupa una versión del agregado: el orden se mantiene.
     *
     * @param  array<string,mixed>  $data
     * @param  list<int>  $recipients
     */
    public function notify(Call $call, string $type, ?int $actorId, array $data, array $recipients, ?string $exceptDeviceId = null): void
    {
        $sequence = $this->nextSequence($call);
        $this->deliver($call, (string) Str::ulid(), $type, $sequence, $actorId, $data, $recipients, $exceptDeviceId, now(), null);
    }

    /**
     * Marca interna (sin publicar ni ocupar versión): p. ej. que un comando idempotente ya se procesó.
     *
     * @param  array<string,mixed>  $data
     */
    public function remember(Call $call, string $type, ?int $actorId, string $dedupeKey, array $data = []): CallEvent
    {
        $event = new CallEvent;
        $event->forceFill([
            'institution_id' => $call->institution_id,
            'call_id' => $call->id,
            'type' => $type,
            'actor_id' => $actorId,
            'data' => $data === [] ? null : $data,
            'dedupe_key' => $dedupeKey,
            'correlation_id' => $this->operation->correlationId(),
            'causation_id' => $this->operation->causationId(),
        ])->save();

        return $event;
    }

    /**
     * Versión siguiente del agregado. Quien registra hechos siempre tiene la fila de la llamada bloqueada
     * (locked() o la transacción que la crea), así que el contador no tiene carreras.
     */
    private function nextSequence(Call $call): int
    {
        $next = (int) $call->event_sequence + 1;
        DB::table('collab_call_calls')->where('id', $call->id)->update(['event_sequence' => $next]);
        $call->forceFill(['event_sequence' => $next])->syncOriginalAttribute('event_sequence');

        return $next;
    }

    /**
     * Con CollaborationRealtime (TransactionalEventRecorder enlazado): se anota en SU outbox dentro de la
     * MISMA transacción del cambio (sin ventana de pérdida) y no se publica por ningún otro camino (sin
     * duplicados). Sin él: el puerto propio de TeamCalls tras confirmar (hoy Noop; la interfaz sondea).
     *
     * @param  array<string,mixed>  $payload
     * @param  list<int>  $recipients
     */
    private function deliver(Call $call, string $id, string $type, int $sequence, ?int $actorId, array $payload, array $recipients,
        ?string $exceptDeviceId, \DateTimeInterface $occurredAt, ?\DateTimeImmutable $expiresAt): void
    {
        if ($this->app->bound(TransactionalEventRecorder::class)) {
            if (DB::transactionLevel() === 0) {
                throw new LogicException('TeamCalls events must be recorded inside the business transaction.');
            }
            $this->app->make(TransactionalEventRecorder::class)->append(
                new InstitutionContext((int) $call->institution_id),
                CallEventEnvelopes::event($id, $type, (string) $call->id, (int) $call->institution_id, $sequence, $actorId,
                    DateTimeImmutable::createFromInterface($occurredAt), $this->operation->correlationId(), $this->operation->causationId(), $payload),
                CallEventEnvelopes::audience((int) $call->institution_id, $recipients, $exceptDeviceId, $expiresAt),
            );

            return;
        }
        $this->afterCommit(new CallEnvelope($id, $type, $call->id, $call->institution_id, $actorId, $payload, $recipients,
            Carbon::instance($occurredAt), $exceptDeviceId));
    }

    private function afterCommit(CallEnvelope $envelope): void
    {
        DB::afterCommit(function () use ($envelope): void {
            try {
                $this->publisher->publish($envelope);
            } catch (Throwable $e) {
                Log::warning('teamcalls.publish_failed', ['type' => $envelope->type, 'call_id' => $envelope->callId, 'error' => class_basename($e)]);
            }
        });
    }

    /** @return list<int> */
    private function participantIds(Call $call): array
    {
        return $call->participants->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
