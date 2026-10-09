<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Throwable;

/**
 * Acción NATIVA de «escribiendo» del canal cuando el asesor inteligente va a contestar un entrante:
 *  - Messenger e Instagram: POST /me/messages {recipient, sender_action: typing_on} (Page Access Token).
 *  - WhatsApp (Cloud API): POST /{phone-number-id}/messages {status: read, message_id: wamid,
 *    typing_indicator: {type: text}}. La API de WhatsApp solo permite el indicador junto con la
 *    marca de LEÍDO del mensaje (doble check azul): no existe «escribiendo» sin «leído».
 * El canal lo apaga solo al enviar la respuesta (o pasados ~20–25 s). Best-effort: nunca lanza ni
 * retiene al llamador más de TIMEOUT_SECONDS; un fallo solo deja un log sin datos personales.
 */
final class AdvisorTypingIndicator
{
    private const TIMEOUT_SECONDS = 5;

    /** «Escribiendo» para el entrante $messageId, solo si el asesor lo va a contestar. */
    public function forMessage(int $messageId): bool
    {
        try {
            $message = SocialMessage::query()->find($messageId);
            $conversation = $message !== null ? SocialConversation::query()->with('channel')->find($message->social_conversation_id) : null;
            $channel = $conversation?->channel;
            if ($message === null || $conversation === null || $channel === null || ! $this->advisorWillReply($channel, $conversation, $message)) {
                return false;
            }

            return $this->send($channel, $conversation, $message);
        } catch (Throwable $e) {
            Log::warning('social.typing: no se pudo enviar «escribiendo»', ['message_id' => $messageId, 'error' => class_basename($e)]);

            return false;
        }
    }

    /** Mismas condiciones básicas con las que el asesor contesta (no se muestra «escribiendo» en vano). */
    private function advisorWillReply(SocialChannel $channel, SocialConversation $conversation, SocialMessage $message): bool
    {
        return SocialAdvisorResponder::channelIsAutomated($channel) && $channel->is_active
            && $message->direction === 'inbound' && $message->sender_type === 'contact'
            && $message->type === 'text' && trim((string) $message->body) !== ''
            && $conversation->advisorMayReply() && $channel->hasSender() && $channel->withinAdvisorSchedule()
            // Ya contestado (por el asesor o por una persona): no se vuelve a mostrar «escribiendo».
            && ! SocialMessage::query()->where('social_conversation_id', $conversation->getKey())
                ->where('direction', 'outbound')->where('id', '>', $message->getKey())->exists();
    }

    private function send(SocialChannel $channel, SocialConversation $conversation, SocialMessage $message): bool
    {
        // Atajo SOLO-LOCAL de verificación sin tokens reales (igual que los envíos): no toca red.
        $fake = config('social.fake_send');
        if (is_string($fake) && $fake !== '' && app()->environment('local')) {
            return true;
        }

        $token = (string) ($channel->credentials['token'] ?? '');
        $recipient = (string) ($conversation->contact_external_id ?? $conversation->external_conversation_id);
        if ($token === '' || $recipient === '') {
            return false;
        }

        $version = (string) config('social.graph_version', 'v26.0');
        $request = match ((string) $conversation->provider) {
            'messenger', 'instagram' => ["https://graph.facebook.com/{$version}/me/messages", ['recipient' => ['id' => $recipient], 'sender_action' => 'typing_on']],
            'whatsapp' => $this->whatsApp($channel, $message, $version),
            default => null,
        };
        if ($request === null) {
            return false;
        }

        $response = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson()->post($request[0], $request[1]);
        if (! $response->successful()) {
            Log::info('social.typing: el canal no aceptó «escribiendo»', ['provider' => (string) $conversation->provider, 'status' => $response->status(), 'code' => $response->json('error.code')]);
        }

        return $response->successful();
    }

    /** @return array{0: string, 1: array<string, mixed>}|null */
    private function whatsApp(SocialChannel $channel, SocialMessage $message, string $version): ?array
    {
        $phoneNumberId = (string) ($channel->external_id ?? '');
        $wamid = (string) $message->external_message_id;
        if ($phoneNumberId === '' || $wamid === '' || ! $channel->canSendViaApi()) {
            return null;
        }

        return ["https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $wamid,
            'typing_indicator' => ['type' => 'text'],
        ]];
    }
}
