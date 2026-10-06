<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use App\Models\User;
use Modules\Ai\Services\AdvisorTurnService;
use Modules\Social\Models\SocialConversation;

/**
 * Control humano de las conversaciones de redes con asesor inteligente:
 *
 *   bot            Asesor inteligente atendiendo
 *   waiting_human  Esperando a una persona (el asesor transfirió o la persona lo pidió)
 *   human          En atención humana (alguien tomó la conversación o respondió)
 *   paused         Automatización pausada
 *
 * El estado de la conversación social es el que manda; además se sincroniza el modo de la
 * memoria del asesor (AdvisorTurnService) para que no responda en paralelo por ninguna vía.
 */
final class SocialAutomationService
{
    public function __construct(private readonly AdvisorTurnService $turns) {}

    /** «Tomar conversación»: una persona la atiende; el asesor deja de responder al instante. */
    public function takeOver(SocialConversation $conversation, User $user): void
    {
        $conversation->automation_state = 'human';
        $conversation->assigned_to = $user->getKey();
        $conversation->save();
        $this->syncAdvisor($conversation, human: true);
    }

    /** «Devolver al asesor inteligente»: vuelve a responder él (sin persona asignada). */
    public function returnToAdvisor(SocialConversation $conversation): void
    {
        $conversation->automation_state = 'bot';
        $conversation->assigned_to = null;
        $conversation->save();
        $this->syncAdvisor($conversation, human: false);
    }

    public function pause(SocialConversation $conversation): void
    {
        $conversation->automation_state = 'paused';
        $conversation->save();
        $this->syncAdvisor($conversation, human: true);
    }

    /** El asesor transfirió o la persona pidió hablar con alguien: queda a la espera del equipo. */
    public function waitForPerson(SocialConversation $conversation): void
    {
        $conversation->automation_state = 'waiting_human';
        $conversation->save();
        $this->syncAdvisor($conversation, human: true);
    }

    /**
     * Una persona del equipo respondió desde la bandeja: si el canal lo indica («Pausar cuando
     * responda una persona»), el asesor deja de responder en esta conversación.
     */
    public function humanReplied(SocialConversation $conversation, User $user): void
    {
        $channel = $conversation->channel;
        if ($channel === null || ! $channel->advisor_enabled || ! $channel->advisor_pause_on_human) {
            return;
        }
        if (in_array($conversation->automation_state ?? 'bot', ['bot', 'waiting_human'], true)) {
            $this->takeOver($conversation, $user);
        }
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
