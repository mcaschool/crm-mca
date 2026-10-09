<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Institutions\Models\Bot;
use Modules\Social\Exceptions\AdvisorAssignmentConflict;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Support\AdvisorDispatcher;

/**
 * Asignación de un asesor inteligente a una cuenta conectada (Página de Messenger, cuenta de
 * Instagram o número de WhatsApp). La cuenta es una fila de social_channels (institución + canal +
 * cuenta) con UN solo asesor (advisor_bot_id): por construcción nunca hay dos asesores automáticos
 * a la vez en la misma cuenta.
 *
 *  - enable():   activa este asesor en la cuenta. Si la cuenta ya la atiende OTRO asesor activado,
 *                NO la toma en silencio: lanza AdvisorAssignmentConflict con su nombre.
 *  - reassign(): reasignación EXPLÍCITA y atómica (fila bloqueada + comprobación del asesor
 *                anterior): el cambio es una sola escritura, sin intervalo con dos asesores.
 *  - disable():  apaga las respuestas automáticas nuevas; no borra conversaciones, mensajes ni la
 *                configuración de la cuenta (horario, mensajes, espera).
 *
 * Todo dentro de la institución activa (el scope de los modelos lo garantiza) y con quién/cuándo.
 */
final class AdvisorChannelAssignment
{
    public function enable(SocialChannel $channel, Bot $bot, User $user): void
    {
        $this->write($channel, $bot, $user, function (SocialChannel $locked) use ($bot): void {
            $current = $locked->advisor_bot_id !== null ? (int) $locked->advisor_bot_id : null;
            if ($locked->advisor_enabled && $current !== null && $current !== (int) $bot->getKey()) {
                throw AdvisorAssignmentConflict::with($locked, Bot::query()->find($current));
            }
        });
    }

    /** Reasignación explícita: solo si la cuenta sigue con el asesor que el usuario vio ($fromBotId). */
    public function reassign(SocialChannel $channel, Bot $bot, User $user, ?int $fromBotId): void
    {
        $this->write($channel, $bot, $user, function (SocialChannel $locked) use ($fromBotId): void {
            if (($locked->advisor_bot_id !== null ? (int) $locked->advisor_bot_id : null) !== $fromBotId) {
                throw AdvisorAssignmentConflict::with($locked, $locked->advisor_bot_id !== null ? Bot::query()->find($locked->advisor_bot_id) : null);
            }
        });
    }

    public function disable(SocialChannel $channel, Bot $bot, User $user): void
    {
        DB::transaction(function () use ($channel, $bot, $user): void {
            $locked = SocialChannel::query()->whereKey($channel->getKey())->lockForUpdate()->firstOrFail();
            // Solo el asesor asignado puede apagar su propia asignación desde su ficha.
            if ((int) $locked->advisor_bot_id !== (int) $bot->getKey() || ! $locked->advisor_enabled) {
                return;
            }
            $locked->advisor_enabled = false;
            $locked->advisor_assigned_by = $user->getKey();
            $locked->advisor_assigned_at = now();
            $locked->save();
        });
    }

    /** @param  callable(SocialChannel): void  $guard */
    private function write(SocialChannel $channel, Bot $bot, User $user, callable $guard): void
    {
        if ($bot->type === 'human') {
            throw new InvalidArgumentException('Solo un asesor inteligente puede atender una cuenta automáticamente.');
        }

        DB::transaction(function () use ($channel, $bot, $user, $guard): void {
            $locked = SocialChannel::query()->whereKey($channel->getKey())->lockForUpdate()->firstOrFail();
            $guard($locked);

            // Activar exige el despacho persistente funcionando (interruptor general + worker vivo).
            if (! $locked->advisor_enabled && ! AdvisorDispatcher::ready()) {
                throw AdvisorAssignmentConflict::notReady((string) AdvisorDispatcher::pendingReason());
            }

            $locked->advisor_bot_id = $bot->getKey();
            $locked->advisor_enabled = true;
            $locked->advisor_assigned_by = $user->getKey();
            $locked->advisor_assigned_at = now();
            $locked->save();
        });
    }
}
