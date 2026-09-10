<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Jobs\ProcessWhatsAppInboundMedia;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Throwable;

/**
 * Núcleo de ingesta (independiente de la ENTRADA: webhook Meta, o cualquier otra fuente
 * que produzca un NormalizedInboundMessage). Guarda conversación + mensaje con:
 *  - Scoping por institución tomado SIEMPRE del canal resuelto (jamás del payload).
 *  - Upsert de conversación por (social_channel_id, external_conversation_id).
 *  - Inserción idempotente del mensaje por (social_conversation_id, external_message_id).
 *  - Todo dentro de una transacción; bloqueo de la conversación para serializar reintentos.
 *  - Tras confirmar (fuera de la transacción), resolución best-effort del nombre/foto del
 *    contacto vía Graph API la PRIMERA vez que llega un entrante sin nombre (Messenger/IG).
 */
final class SocialIngestService
{
    public function __construct(
        private readonly CurrentInstitution $context,
        private readonly ContactProfileResolver $profiles,
    ) {}

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

        $result = $this->context->runFor($channel->institution_id, fn (): IngestResult => DB::transaction(
            fn (): IngestResult => $this->store($channel, $m)
        ));

        // Resolución de perfil FUERA de la transacción (no hace HTTP con la fila bloqueada) y
        // best-effort: si falla, el mensaje ya quedó ingerido. Solo en el PRIMER entrante de un
        // contacto sin nombre (Messenger/IG); tras el primer éxito no vuelve a llamar.
        if ($result->status === 'created' && $result->conversationId !== null && $m->direction === 'inbound') {
            $this->resolveContactProfile($channel, $m->provider, $result->conversationId);
        }

        // Media de WhatsApp: el webhook trae un media ID (no URL). La descarga NO puede
        // bloquear el 200 a Meta → se despacha tras enviar la respuesta (afterResponse),
        // en un Job idempotente y best-effort: si falla, el mensaje ya quedó ingerido con
        // su provider_media_id. Solo en 'created': un reintento de Meta (duplicate) no
        // vuelve a descargar.
        if ($result->status === 'created' && $result->messageId !== null
            && $m->provider === 'whatsapp' && $m->attachments !== null) {
            ProcessWhatsAppInboundMedia::dispatchAfterResponse($channel->id, $result->messageId, $channel->institution_id);
        }

        return $result;
    }

    /**
     * Aplica un estado de WhatsApp (value.statuses) a un mensaje YA existente, localizado
     * por wamid DENTRO del canal correspondiente (y por tanto de su institución). Nunca
     * lanza ni crea mensajes fantasma: un wamid desconocido solo deja un log seguro.
     *
     * @return 'updated'|'ignored'|'unknown'|'parked'
     */
    public function applyStatus(NormalizedStatus $s): string
    {
        $channel = $this->context->runGlobally(fn (): ?SocialChannel => SocialChannel::query()
            ->where('provider', $s->provider)
            ->where('external_id', $s->channelExternalId)
            ->first());

        if ($channel === null) {
            Log::info('social.status: canal no configurado, estado aparcado', [
                'provider' => $s->provider,
                'channel_external_id' => $s->channelExternalId,
            ]);

            return 'parked';
        }

        return $this->context->runFor($channel->institution_id, function () use ($channel, $s): string {
            $message = SocialMessage::query()
                ->where('external_message_id', $s->messageExternalId)
                ->whereHas('conversation', fn ($q) => $q->where('social_channel_id', $channel->id))
                ->first();

            if ($message === null) {
                // Solo información segura: nunca tokens ni payloads (el wamid no es secreto).
                Log::info('social.status: wamid desconocido, estado ignorado', [
                    'provider' => $s->provider,
                    'status' => $s->status,
                ]);

                return 'unknown';
            }

            if (! $this->statusAdvances((string) ($message->status ?? ''), $s->status)) {
                return 'ignored'; // p. ej. un delivered tardío tras un read: no hay regresión.
            }

            $message->status = $s->status;
            $message->save();

            return 'updated';
        });
    }

    /**
     * Máquina de estados del saliente de WhatsApp (progreso estricto, sin regresiones):
     *
     *   inicial (pending/…) → sent | failed
     *   sent                → delivered | read | failed
     *   delivered           → read            (NUNCA → sent ni → failed tardíos)
     *   read                → TERMINAL        (nada lo cambia)
     *   failed              → TERMINAL        (sin recuperación: un wamid que Meta reportó
     *                                          fallido no vuelve a la vida; un reenvío desde
     *                                          el panel crea un mensaje NUEVO con otro wamid)
     */
    private function statusAdvances(string $current, string $incoming): bool
    {
        // Terminales: read y failed no admiten ningún cambio posterior.
        if ($current === 'read' || $current === 'failed') {
            return false;
        }

        $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3];
        $currentRank = $rank[$current] ?? 0; // pending/failed_window/'' = estado inicial

        if ($incoming === 'failed') {
            // failed solo desde el estado inicial o desde sent: un failed tardío jamás
            // desmiente un delivered (y read ya es terminal arriba).
            return $currentRank <= 1;
        }

        // sent/delivered/read solo AVANZAN: un sent tardío no degrada delivered, etc.
        return ($rank[$incoming] ?? 0) > $currentRank;
    }

    /**
     * Rellena nombre/foto del contacto la primera vez (contact_name vacío) para Messenger/IG.
     * Nunca lanza: cualquier fallo de Graph se ignora (el resolver ya devuelve nulls).
     */
    private function resolveContactProfile(SocialChannel $channel, string $provider, int $conversationId): void
    {
        if (! in_array($provider, ['messenger', 'instagram'], true)) {
            return;
        }

        // Best-effort de verdad: cualquier fallo aquí (Graph, o el propio save) se ignora. El
        // mensaje YA quedó ingerido antes de llegar a este punto; resolver el perfil nunca debe
        // tumbar el webhook.
        try {
            $this->context->runFor($channel->institution_id, function () use ($channel, $provider, $conversationId): void {
                $conversation = SocialConversation::query()->find($conversationId);
                if ($conversation === null
                    || ((string) ($conversation->contact_name ?? '')) !== ''
                    || ((string) ($conversation->contact_external_id ?? '')) === '') {
                    return; // ya tiene nombre, o no hay id de contacto que resolver
                }

                $profile = $this->profiles->resolve($channel, $provider, (string) $conversation->contact_external_id);
                if ($profile['name'] === null && $profile['avatar'] === null) {
                    return;
                }

                $conversation->contact_name = $profile['name'] ?? $conversation->contact_name;
                $conversation->contact_avatar_url = $profile['avatar'] ?? $conversation->contact_avatar_url;
                $conversation->save();
            });
        } catch (Throwable $e) {
            Log::info('social.contact.resolve: no se pudo guardar el perfil', ['provider' => $provider, 'error' => $e->getMessage()]);
        }
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
