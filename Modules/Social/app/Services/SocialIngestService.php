<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

/**
 * Núcleo de ingesta (independiente de la ENTRADA: webhook Meta, o cualquier otra fuente
 * que produzca un NormalizedInboundMessage). Guarda conversación + mensaje con:
 *  - Scoping por institución tomado SIEMPRE del canal resuelto (jamás del payload).
 *  - Upsert de conversación por (social_channel_id, external_conversation_id).
 *  - Inserción idempotente del mensaje por (social_conversation_id, external_message_id).
 *  - Todo dentro de una transacción; bloqueo de la conversación para serializar reintentos.
 */
final class SocialIngestService
{
    public function __construct(private readonly CurrentInstitution $context) {}

    public function ingest(NormalizedMessage $m): IngestResult
    {
        // El canal se resuelve SIN contexto de institución todavía (modo global desactiva
        // el InstitutionScope limpiamente, sin withoutGlobalScope). Su institución es la
        // fuente de verdad para todo lo que se cree a continuación.
        $channel = $this->context->runGlobally(fn (): ?SocialChannel => SocialChannel::query()
            ->where('provider', $m->provider)
            ->where('external_id', $m->channelExternalId)
            ->first());

        if ($channel === null) {
            Log::info('social.ingest: canal no configurado, evento aparcado', [
                'provider' => $m->provider,
                'channel_external_id' => $m->channelExternalId,
            ]);

            return IngestResult::parked();
        }

        return $this->context->runFor($channel->institution_id, fn (): IngestResult => DB::transaction(
            fn (): IngestResult => $this->store($channel, $m)
        ));
    }

    private function store(SocialChannel $channel, NormalizedMessage $m): IngestResult
    {
        $timestamp = $m->providerTimestamp !== null ? Carbon::instance($m->providerTimestamp) : now();

        $conversation = SocialConversation::query()
            ->where('social_channel_id', $channel->id)
            ->where('external_conversation_id', $m->conversationExternalId)
            ->lockForUpdate()
            ->first();

        if ($conversation === null) {
            $conversation = new SocialConversation;
            $conversation->social_channel_id = $channel->id;
            $conversation->provider = $m->provider;
            $conversation->external_conversation_id = $m->conversationExternalId;
            $conversation->status = 'open';
            $conversation->unread_count = 0;
        }

        // Enriquecer datos de contacto solo si vienen (no pisar con null lo ya conocido).
        $conversation->contact_name = $m->contactName ?? $conversation->contact_name;
        $conversation->contact_external_id = $m->contactExternalId ?? $conversation->contact_external_id;
        $conversation->contact_avatar_url = $m->contactAvatarUrl ?? $conversation->contact_avatar_url;
        $conversation->save();

        // Idempotencia: si el mensaje ya existe (reintento del webhook), no duplicar
        // ni volver a subir unread/preview.
        $existing = SocialMessage::query()
            ->where('social_conversation_id', $conversation->id)
            ->where('external_message_id', $m->messageExternalId)
            ->first();

        if ($existing !== null) {
            return IngestResult::duplicate($conversation->id, $existing->id);
        }

        $message = new SocialMessage;
        $message->social_conversation_id = $conversation->id;
        $message->external_message_id = $m->messageExternalId;
        $message->direction = $m->direction;
        $message->type = $m->type;
        $message->body = $m->body;
        $message->attachments = $m->attachments;
        $message->status = $m->direction === 'inbound' ? 'received' : 'sent';
        $message->sender_type = $m->senderType;
        $message->sent_by = null;
        $message->provider_timestamp = $timestamp;
        $message->save();

        // Solo lo ENTRANTE incrementa no leídos; un saliente (agente o app del teléfono) no.
        if ($m->direction === 'inbound') {
            $conversation->unread_count = $conversation->unread_count + 1;
        }
        $conversation->last_message_preview = Str::limit((string) ($m->body ?? '['.$m->type.']'), 140);
        $conversation->last_message_at = $timestamp;
        $conversation->save();

        return IngestResult::created($conversation->id, $message->id);
    }
}
