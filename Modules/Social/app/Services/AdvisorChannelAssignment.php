<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Institutions\Models\Bot;
use Modules\Social\Exceptions\AdvisorAssignmentConflict;
use Modules\Social\Exceptions\AdvisorTurnInProgress;
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
 *  - configure(): el formulario general del canal (cualquier cambio de sus ajustes del asesor).
 *
 * Activar (o volver a activar) el asesor y cambiar de asesor con él activado establecen ANTES de
 * guardar el corte determinista de AdvisorCutoff sobre todas las conversaciones de la cuenta
 * (recibos «before_activation»): nunca se responde un mensaje recibido antes, aunque coincida en
 * el mismo segundo. Si un worker tiene el turno de alguna conversación, se revierte todo
 * (AdvisorAssignmentConflict::busy) sin marcar mensajes ni cambiar la asignación.
 *
 * Todo dentro de la institución activa (el scope de los modelos lo garantiza) y con quién/cuándo.
 */
final class AdvisorChannelAssignment
{
    public function __construct(private readonly AdvisorCutoff $cutoff) {}

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
            $this->save($locked, $user);
        });
    }

    /**
     * Formulario del canal: $mutate cambia los ajustes sobre la fila BLOQUEADA y se guarda con el
     * corte si activa el asesor o cambia de asesor estando activado. Devuelve la cuenta guardada.
     *
     * @param  callable(SocialChannel): void  $mutate
     */
    public function configure(SocialChannel $channel, User $user, callable $mutate): SocialChannel
    {
        return DB::transaction(function () use ($channel, $user, $mutate): SocialChannel {
            $locked = SocialChannel::query()->whereKey($channel->getKey())->lockForUpdate()->firstOrFail();
            $mutate($locked);
            $this->save($locked, $user);

            return $locked;
        });
    }

    /** @param  callable(SocialChannel): void  $guard */
    private function write(SocialChannel $channel, Bot $bot, User $user, callable $guard): void
    {
        if ($bot->type === 'human') {
            throw new InvalidArgumentException(__('Solo un asesor inteligente puede atender una cuenta automáticamente.'));
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
            $this->save($locked, $user);
        });
    }

    /**
     * Guarda la cuenta bloqueada. Si el asesor queda ACTIVADO y antes no lo estaba (o era otro
     * asesor), primero el corte de todas sus conversaciones; con un worker trabajando se lanza
     * AdvisorAssignmentConflict::busy y la transacción se revierte entera.
     */
    private function save(SocialChannel $locked, User $user): void
    {
        if ($locked->isDirty(['advisor_enabled', 'advisor_bot_id'])) {
            $activating = $locked->advisor_enabled && $locked->advisor_bot_id !== null
                && (! $locked->getOriginal('advisor_enabled') || (int) $locked->getOriginal('advisor_bot_id') !== (int) $locked->advisor_bot_id);
            if ($activating) {
                try {
                    $this->cutoff->channel($locked, AdvisorCutoff::BEFORE_ACTIVATION);
                } catch (AdvisorTurnInProgress $e) {
                    throw AdvisorAssignmentConflict::busy($e->getMessage());
                }
            }
            $locked->advisor_assigned_by = $user->getKey();
            $locked->advisor_assigned_at = now();
        }
        $locked->save();
    }
}
