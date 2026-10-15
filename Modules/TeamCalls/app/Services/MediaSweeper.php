<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Services;

use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Enums\CallStatus;
use Modules\TeamCalls\Enums\MediaState;
use Modules\TeamCalls\Models\Call;
use Throwable;

/**
 * Red de seguridad por si se pierde algún webhook: contrasta las salas REALES del servidor de medios con
 * las llamadas. Cierra salas de TeamCalls sin llamada viva, desconecta a quien no debería estar (p. ej.
 * expulsado con una credencial todavía vigente) y da por desconectado a quien ya no está.
 */
final class MediaSweeper
{
    public function __construct(
        private readonly CallService $calls,
        private readonly MediaRoomService $media,
        private readonly CurrentInstitution $context,
    ) {}

    /** @return array{rooms: int, closed: int, fixes: int} */
    public function sweep(int $limit = 200): array
    {
        if (! $this->media->available()) {
            return ['rooms' => 0, 'closed' => 0, 'fixes' => 0];
        }
        $rooms = array_values(array_filter($this->media->rooms(), fn (string $r) => str_starts_with($r, Call::ROOM_PREFIX)));

        /** @var array<string, array{id: string, institution_id: int}> $calls sala => llamada */
        $calls = $this->context->runGlobally(function () use ($rooms, $limit): array {
            $live = array_map(fn (CallStatus $s) => $s->value, CallStatus::live());

            return Call::query()
                ->where(fn ($q) => $q->whereIn('room_name', $rooms === [] ? [''] : $rooms)
                    ->orWhere(fn ($c) => $c->whereIn('status', $live)
                        ->whereHas('participants', fn ($p) => $p->where('media_state', MediaState::Connected->value))))
                ->limit($limit)
                ->get(['id', 'institution_id', 'room_name'])
                ->mapWithKeys(fn (Call $c) => [(string) $c->room_name => ['id' => (string) $c->id, 'institution_id' => (int) $c->institution_id]])
                ->all();
        });

        $closed = 0;
        foreach (array_diff($rooms, array_keys($calls)) as $orphan) {
            try {
                $this->media->closeRoom($orphan);
                $closed++;
            } catch (Throwable $e) {
                Log::warning('teamcalls.media_sweep_failed', ['error' => class_basename($e)]);
            }
        }
        $fixes = 0;
        foreach ($calls as $call) {
            try {
                $fixes += (int) $this->context->runFor($call['institution_id'], fn () => $this->calls->reconcileMedia($call['id']));
            } catch (Throwable $e) {
                Log::warning('teamcalls.media_sweep_failed', ['call_id' => $call['id'], 'error' => class_basename($e)]);
            }
        }

        return ['rooms' => count($rooms), 'closed' => $closed, 'fixes' => $fixes];
    }
}
