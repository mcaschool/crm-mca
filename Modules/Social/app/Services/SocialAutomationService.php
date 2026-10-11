<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Ai\Services\AdvisorTurnService;
use Modules\Social\Exceptions\AdvisorTurnInProgress;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Support\SocialAutomationAlerts;

/**
 * Control humano de las conversaciones de redes con asesor inteligente:
 *
 *   bot            Asesor inteligente atendiendo
 *   waiting_human  Esperando a una persona (el asesor transfirió o la persona lo pidió)
 *   human          En atención humana (alguien tomó la conversación o respondió)
 *   paused         Automatización pausada
 *   error          Error de automatización (fallo no recuperable, con motivo visible)
 *
 * Cada cambio deja motivo (AUTOMATION_REASONS), quién (null = el sistema) y cuándo. El estado de
 * la conversación social es el que manda; además se sincroniza el modo de la memoria del asesor
 * (AdvisorTurnService) para que no responda en paralelo por ninguna vía.
 */
final class SocialAutomationService
{
    /** Duración máxima del turno de un worker sobre una conversación (si muere, caduca solo). */
    private const LEASE_SECONDS = 180;

    public function __construct(
        private readonly AdvisorTurnService $turns,
        private readonly SocialAutomationAlerts $alerts,
        private readonly AdvisorCutoff $cutoff,
    ) {}

    /** «Tomar conversación»: una persona la atiende; el asesor deja de responder al instante. */
    public function takeOver(SocialConversation $conversation, ?User $user, string $reason = 'taken_over'): void
    {
        if ($user !== null) {
            $conversation->assigned_to = $user->getKey();
        }
        $this->transition($conversation, 'human', $reason, $user);
    }

    /**
     * «Reactivar el asesor»: vuelve a responder él (sin persona asignada), pero SOLO a los mensajes
     * que lleguen a partir de ahora. En una transacción: corte determinista (AdvisorCutoff: bloquea
     * la conversación, respeta el turno de un worker y marca lo ya recibido con su recibo de omisión)
     * y solo después el cambio a «bot». Si un worker tiene el turno, no cambia nada y devuelve false
     * (se reintenta en unos segundos); nunca se le quita el turno.
     */
    public function returnToAdvisor(SocialConversation $conversation, ?User $user = null): bool
    {
        try {
            $done = DB::transaction(function () use ($conversation, $user): bool {
                $locked = $this->cutoff->conversation($conversation, AdvisorCutoff::BEFORE_REACTIVATION);
                if ($locked === null) {
                    return false;
                }
                $locked->assigned_to = null;
                $this->transition($locked, 'bot', 'reactivated', $user);

                return true;
            }, 3);
        } catch (AdvisorTurnInProgress) {
            return false;
        }
        if ($done) {
            $conversation->refresh();
        }

        return $done;
    }

    public function pause(SocialConversation $conversation, ?User $user = null): void
    {
        $this->transition($conversation, 'paused', 'paused_by_user', $user);
    }

    /** El asesor transfirió o la persona pidió hablar con alguien: queda a la espera del equipo. */
    public function waitForPerson(SocialConversation $conversation, string $reason = 'advisor_handoff'): void
    {
        $this->transition($conversation, 'waiting_human', $reason, null);
    }

    /**
     * Fallo no recuperable de la automatización: la conversación queda en «Error de automatización»
     * con el motivo visible y se avisa a los administradores (sistema de alertas existente). Una
     * persona puede responder desde la bandeja y reactivar el asesor cuando esté resuelto.
     */
    public function fail(SocialConversation $conversation, string $reason): void
    {
        $this->transition($conversation, SocialConversation::ERROR_STATE, $reason, null);
        $this->alerts->automationStopped($conversation, $reason);
    }

    /**
     * Una persona del equipo respondió (desde la bandeja, o desde la app de WhatsApp del teléfono):
     * si la cuenta tiene asesor, deja de responder en esta conversación de inmediato y hasta que
     * alguien lo reactive expresamente. Es obligatorio: no depende de ninguna opción del canal.
     */
    public function humanReplied(SocialConversation $conversation, ?User $user, string $reason = 'human_replied'): void
    {
        if ($conversation->channel?->advisor_bot_id === null) {
            return;
        }
        if (in_array($conversation->automation_state ?? 'bot', ['bot', 'waiting_human', SocialConversation::ERROR_STATE], true)) {
            $this->takeOver($conversation, $user, $reason);
        }
    }

    /**
     * Turno PERSISTENTE (en la base de datos, no en caché) de un worker sobre la conversación: una
     * actualización atómica que solo gana si nadie lo tiene o si caducó. Devuelve false si otro
     * worker está atendiendo esta conversación ahora mismo.
     */
    public function claim(SocialConversation $conversation): bool
    {
        $claimed = SocialConversation::query()
            ->whereKey($conversation->getKey())
            ->where(fn ($q) => $q->whereNull('advisor_lease_until')->orWhere('advisor_lease_until', '<', now()))
            ->update(['advisor_lease_until' => now()->addSeconds(self::LEASE_SECONDS)]);

        return $claimed === 1;
    }

    public function release(SocialConversation $conversation): void
    {
        SocialConversation::query()->whereKey($conversation->getKey())->update(['advisor_lease_until' => null]);
    }

    private function transition(SocialConversation $conversation, string $state, string $reason, ?User $user): void
    {
        $conversation->automation_state = $state;
        $conversation->automation_reason = $reason;
        $conversation->automation_changed_by = $user?->getKey();
        $conversation->automation_changed_at = now();
        $conversation->save();
        $this->closeUnresolvedStreak($conversation, $reason);
        $this->syncAdvisor($conversation, human: $state !== 'bot');
    }

    /**
     * Todo cambio de estado (reactivación, toma, pausa, transferencia…) reinicia la cuenta de
     * respuestas «unresolved» seguidas: se marca en la traza de la última respuesta del asesor
     * (ai_meta.streak_reset = motivo). Es exacto aunque el cambio y esa respuesta compartan el mismo
     * segundo (los timestamps de la base no distinguen el orden dentro de un segundo).
     */
    private function closeUnresolvedStreak(SocialConversation $conversation, string $reason): void
    {
        $last = SocialMessage::query()
            ->where('social_conversation_id', $conversation->getKey())
            ->where('direction', 'outbound')
            ->orderByDesc('id')
            ->first();
        $meta = $last?->ai_meta;
        if ($last === null || $last->sender_type !== 'bot' || ! is_array($meta) || ($meta['kind'] ?? null) !== 'unresolved' || isset($meta['streak_reset'])) {
            return;
        }
        $last->ai_meta = $meta + ['streak_reset' => $reason];
        $last->saveQuietly();
    }

    private function syncAdvisor(SocialConversation $conversation, bool $human): void
    {
        $botId = $conversation->channel?->advisor_bot_id;
        if ($botId === null) {
            return;
        }

        $advisorConversation = $this->turns->findConversation((int) $botId, (string) $conversation->provider, (string) $conversation->external_conversation_id);
        if ($advisorConversation === null) {
            return;
        }

        $human ? $this->turns->handOff($advisorConversation) : $this->turns->releaseToAdvisor($advisorConversation);
    }
}
