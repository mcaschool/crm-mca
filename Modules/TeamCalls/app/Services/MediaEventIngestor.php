<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Services;

use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Support\MediaEvent;
use Modules\TeamCalls\Support\MediaIdentity;
use Throwable;

/**
 * Aplica eventos VERIFICADOS del servidor de medios al motor, sin sustituir su máquina de estados:
 * la sala identifica la llamada (y con ella su institución, que se fija para todo el proceso) y la
 * identidad, a la persona y su dispositivo. Todo lo demás lo decide CallService::applyMediaEvent.
 *
 *  - Sala de TeamCalls sin llamada: se cierra. Sala ajena (otro prefijo): se ignora.
 *  - Identidad que no es de TeamCalls en una de sus salas: se desconecta.
 *  - Una llamada terminada no se reactiva; si alguien entra a su sala, se le desconecta y se cierra.
 */
final class MediaEventIngestor
{
    public function __construct(
        private readonly CallService $calls,
        private readonly MediaRoomService $media,
        private readonly CurrentInstitution $context,
    ) {}

    /** @return string resultado (applied, ignored, unknown_room, unknown_identity, rejected) */
    public function ingest(MediaEvent $event): string
    {
        if (! str_starts_with($event->room, Call::ROOM_PREFIX)) {
            return 'ignored'; // sala de otra aplicación en el mismo servidor de medios
        }
        /** @var array{id: string, institution_id: int}|null $call */
        $call = $this->context->runGlobally(function () use ($event): ?array {
            $row = Call::query()->where('room_name', $event->room)->first(['id', 'institution_id']);

            return $row !== null ? ['id' => (string) $row->id, 'institution_id' => (int) $row->institution_id] : null;
        });
        if ($call === null) {
            if (in_array($event->kind, ['room_started', 'participant_joined'], true)) {
                $this->safely(fn () => $this->media->closeRoom($event->room));
            }

            return 'unknown_room';
        }

        return $this->context->runFor($call['institution_id'], fn (): string => $this->apply($call['id'], $event));
    }

    private function apply(string $callId, MediaEvent $event): string
    {
        try {
            switch ($event->kind) {
                case 'participant_joined':
                case 'participant_left':
                    $identity = $event->identity !== null ? MediaIdentity::parse($event->identity) : null;
                    if ($identity === null) {
                        if ($event->kind === 'participant_joined' && $event->identity !== null && $event->identity !== '') {
                            $this->safely(fn () => $this->media->removeParticipant($event->room, (string) $event->identity));
                        }

                        return 'unknown_identity';
                    }
                    $this->calls->applyMediaEvent($callId, $event->id, $event->kind === 'participant_joined' ? 'joined' : 'left',
                        $identity->userId, $identity->deviceId, $event->session);

                    return 'applied';
                case 'room_finished':
                    $this->calls->applyRoomFinished($callId, $event->id);

                    return 'applied';
                case 'room_started':
                    $this->calls->reconcileMedia($callId); // cierra la sala si la llamada ya no está viva

                    return 'applied';
                default:
                    return 'ignored';
            }
        } catch (CallException $e) {
            // Datos que el motor no acepta (p. ej. dispositivo con formato inválido): no se reintenta.
            Log::notice('teamcalls.media_event_rejected', ['call_id' => $callId, 'kind' => $event->kind, 'reason' => $e->reason]);

            return 'rejected';
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
}
