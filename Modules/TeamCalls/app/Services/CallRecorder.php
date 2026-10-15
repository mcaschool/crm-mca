<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallEvent;
use Modules\TeamCalls\Support\CallEnvelope;
use Throwable;

/**
 * Registra cada hecho de una llamada en collab_call_events (dentro de la transacción) y, SOLO cuando
 * la transacción se confirma, lo publica en tiempo real y emite el evento interno de Laravel. Un
 * fallo al publicar se registra y nunca deshace la operación.
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

    public function __construct(private readonly CallEventPublisher $publisher) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  list<int>|null  $recipients  null = todos los participantes de la llamada
     */
    public function record(Call $call, string $type, ?int $actorId, ?int $subjectId = null, array $data = [], ?array $recipients = null, ?string $exceptDeviceId = null, ?string $dedupeKey = null): CallEvent
    {
        $event = new CallEvent;
        $event->forceFill([
            'institution_id' => $call->institution_id,
            'call_id' => $call->id,
            'type' => $type,
            'actor_id' => $actorId,
            'subject_id' => $subjectId,
            'data' => $data === [] ? null : $data,
            'dedupe_key' => $dedupeKey,
        ])->save();

        $payload = ($subjectId !== null ? ['user_id' => $subjectId] : []) + $data;
        $this->afterCommit(new CallEnvelope($event->id, $type, $call->id, $call->institution_id, $actorId, $payload,
            $recipients ?? $this->participantIds($call), $event->created_at, $exceptDeviceId));

        $class = self::DOMAIN_EVENTS[$type] ?? null;
        if ($class !== null) {
            DB::afterCommit(fn () => event(new $class($call->id, $call->institution_id, $actorId, $subjectId, $data, $event->id)));
        }

        return $event;
    }

    /**
     * Aviso solo en tiempo real, sin traza persistente (p. ej. call.answered_elsewhere a los demás
     * dispositivos de la misma persona).
     *
     * @param  array<string,mixed>  $data
     * @param  list<int>  $recipients
     */
    public function notify(Call $call, string $type, ?int $actorId, array $data, array $recipients, ?string $exceptDeviceId = null): void
    {
        $this->afterCommit(new CallEnvelope((string) Str::ulid(), $type, $call->id, $call->institution_id, $actorId, $data, $recipients, now(), $exceptDeviceId));
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
