<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Ai\Services\AdvisorTurnService;
use Modules\Social\Exceptions\AdvisorTurnInProgress;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

/**
 * CORTE determinista entre los mensajes «anteriores» y «posteriores» a un cambio del asesor. Es la
 * ÚNICA implementación, compartida por:
 *  - la reactivación de una conversación (SocialAutomationService::returnToAdvisor);
 *  - la activación, reactivación y reasignación del asesor de una cuenta completa
 *    (AdvisorChannelAssignment, también desde el formulario del canal).
 *
 * No compara horas (los timestamps tienen precisión de segundos). Se llama DENTRO de la transacción
 * de la operación y ANTES de guardar el cambio:
 *  1. bloquea (FOR UPDATE, en orden de id) las conversaciones afectadas: un worker que intenta
 *     reclamar una (claim) y el webhook que guarda un mensaje nuevo esperan al final de la transacción;
 *  2. si alguna tiene un worker con turno vigente (lease), lanza AdvisorTurnInProgress: quien llama
 *     deja que la transacción se revierta entera (sin marcar nada ni guardar el cambio);
 *  3. bloquea y marca con el recibo persistente de idempotencia ($status) los mensajes entrantes ya
 *     recibidos sin decisión (los omitidos pasan al estado del corte; los respondidos no se tocan).
 * Todo lo que se guarda después de la transacción es posterior al corte y sigue siendo procesable.
 *
 * Alcance: mensajes de las últimas WINDOW_HOURS. Los más antiguos nunca se responden ni se agrupan
 * (el asesor descarta por antigüedad todo lo que tenga más de 15 minutos).
 */
final class AdvisorCutoff
{
    public const BEFORE_REACTIVATION = AdvisorTurnService::RECEIPT_BEFORE_REACTIVATION;

    public const BEFORE_ACTIVATION = AdvisorTurnService::RECEIPT_BEFORE_ACTIVATION;

    private const WINDOW_HOURS = 24;

    public function __construct(private readonly AdvisorTurnService $turns) {}

    /**
     * Corte de UNA conversación. Devuelve la conversación bloqueada (para aplicar el cambio sobre ella).
     *
     * @throws AdvisorTurnInProgress
     */
    public function conversation(SocialConversation $conversation, string $status): ?SocialConversation
    {
        return $this->cut(SocialConversation::query()->with('channel')->whereKey($conversation->getKey()), $status)->first();
    }

    /**
     * Corte de TODAS las conversaciones de una cuenta (el canal ya debe estar bloqueado por quien llama).
     *
     * @throws AdvisorTurnInProgress
     */
    public function channel(SocialChannel $channel, string $status): void
    {
        $this->cut(SocialConversation::query()->where('social_channel_id', $channel->getKey()), $status);
    }

    /**
     * @param  Builder<SocialConversation>  $conversations
     * @return Collection<int, SocialConversation>
     */
    private function cut(Builder $conversations, string $status): Collection
    {
        $locked = $conversations->orderBy('id')->lockForUpdate()->get();
        if ($locked->contains(fn (SocialConversation $c): bool => $c->advisor_lease_until !== null && $c->advisor_lease_until->isFuture())) {
            throw new AdvisorTurnInProgress(__('El asesor está terminando un turno en una conversación de esta cuenta. Vuelve a intentarlo en unos segundos.'));
        }

        foreach ($locked->groupBy(fn (SocialConversation $c): string => $c->institution_id.'|'.$c->provider) as $group) {
            /** @var SocialConversation $first */
            $first = $group->first();
            $ids = SocialMessage::query()
                ->whereIn('social_conversation_id', $group->modelKeys())
                ->where('direction', 'inbound')
                ->whereNotNull('external_message_id')
                ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('external_message_id')
                ->map(fn ($id): string => (string) $id)
                ->all();
            $this->turns->recordCutoff((int) $first->institution_id, (string) $first->provider, $ids, $status);
        }

        return $locked->values();
    }
}
