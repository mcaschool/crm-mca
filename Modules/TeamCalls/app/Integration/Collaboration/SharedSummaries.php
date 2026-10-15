<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use Modules\CollaborationCore\Data\CallSummary as SharedCallSummary;
use Modules\CollaborationCore\Data\ConversationRef as SharedConversationRef;
use Modules\CollaborationCore\Data\JoinCredentials as SharedJoinCredentials;
use Modules\CollaborationCore\Data\ParticipantSummary as SharedParticipantSummary;
use Modules\CollaborationCore\Enums\CallMedia;
use Modules\CollaborationCore\Enums\CallScope;
use Modules\CollaborationCore\Enums\CallStatus;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Enums\ParticipantRole;
use Modules\CollaborationCore\Enums\ParticipantState;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\TeamCalls\Support\CallSummary;
use Modules\TeamCalls\Support\JoinCredentials;
use Modules\TeamCalls\Support\ParticipantSummary;

/**
 * Resúmenes internos de TeamCalls → objetos del contrato común. Solo datos públicos de la llamada:
 * nada de nombres de sala, claves de idempotencia, estado de medios ni dispositivos (deviceId = null).
 */
final class SharedSummaries
{
    public static function call(CallSummary $s): SharedCallSummary
    {
        return new SharedCallSummary(
            id: $s->id,
            institutionId: $s->institutionId,
            media: CallMedia::from($s->media),
            scope: CallScope::from($s->scope),
            status: CallStatus::from($s->status),
            conversation: $s->conversation !== null
                ? new SharedConversationRef(ConversationType::from($s->conversation->type), $s->conversation->id) : null,
            initiatorId: $s->initiatorId,
            participants: array_map(fn (ParticipantSummary $p) => new SharedParticipantSummary(
                $p->userId, ParticipantRole::from($p->role), ParticipantState::from($p->state)), $s->participants),
            createdAt: $s->createdAt->toDateTimeImmutable(),
            answeredAt: $s->answeredAt?->toDateTimeImmutable(),
            endedAt: $s->endedAt?->toDateTimeImmutable(),
            endReason: $s->endReason,
            viewerState: $s->viewerState !== null ? ParticipantState::from($s->viewerState) : null,
        );
    }

    /**
     * El contrato común exige https/wss. Un servidor de medios de desarrollo con ws:// (permitido solo con
     * la excepción explícita de desarrollo de LiveKitConfig) NO se rebaja aquí: se informa como medios no
     * disponibles por esta puerta, y la API web interna de desarrollo sigue funcionando.
     */
    public static function credentials(JoinCredentials $c): SharedJoinCredentials
    {
        if (! in_array(parse_url($c->serverUrl, PHP_URL_SCHEME), ['https', 'wss'], true)) {
            throw new ContractException('media_unavailable', 503);
        }

        return new SharedJoinCredentials($c->serverUrl, $c->token, $c->identity, $c->expiresAt->toDateTimeImmutable());
    }
}
