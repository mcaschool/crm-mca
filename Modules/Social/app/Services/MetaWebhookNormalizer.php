<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Carbon\CarbonImmutable;

/**
 * Traduce el payload CRUDO de Meta al formato interno (NormalizedMessage).
 *
 * Robustez: solo emite los eventos que sabemos ingerir. Todo lo demás — estados de entrega,
 * reacciones, echoes de IG/Messenger (is_echo), comentarios/feed, postbacks — se DESCARTA
 * silenciosamente. El controlador responde 200 igualmente para no gatillar reintentos.
 *
 * Excepción (Bloque 3.1): en WhatsApp, el eco de COEXISTENCIA (field 'smb_message_echoes')
 * SÍ se ingiere, como mensaje SALIENTE originado en la app del teléfono del negocio.
 */
final class MetaWebhookNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, NormalizedMessage>
     */
    public function normalize(string $provider, array $payload): array
    {
        return match ($provider) {
            'whatsapp' => $this->fromWhatsapp($payload),
            'messenger', 'instagram' => $this->fromMessaging($provider, $payload),
            default => [],
        };
    }

    /**
     * WhatsApp Business (object 'whatsapp_business_account'):
     *  - field 'messages': mensajes ENTRANTES del usuario (value.messages).
     *  - field 'smb_message_echoes': ecos de COEXISTENCIA (value.message_echoes), lo que el
     *    negocio envía desde la app del teléfono → se ingiere como SALIENTE (sender 'app').
     * Los value.statuses (sent/delivered/read) se ignoran.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, NormalizedMessage>
     */
    private function fromWhatsapp(array $payload): array
    {
        $out = [];

        foreach ($this->arr($payload, 'entry') as $entry) {
            foreach ($this->arr($entry, 'changes') as $change) {
                $field = $change['field'] ?? null;
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $channelId = (string) ($this->obj($value, 'metadata')['phone_number_id'] ?? '');
                if ($channelId === '') {
                    continue;
                }

                if ($field === 'messages') {
                    $out = array_merge($out, $this->whatsappInbound($value, $channelId));
                } elseif ($field === 'smb_message_echoes') {
                    $out = array_merge($out, $this->whatsappEchoes($value, $channelId));
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<int, NormalizedMessage>
     */
    private function whatsappInbound(array $value, string $channelId): array
    {
        // wa_id → nombre de perfil (si viene en contacts[]).
        $names = [];
        foreach ($this->arr($value, 'contacts') as $contact) {
            $waId = (string) ($contact['wa_id'] ?? '');
            $name = $this->obj($contact, 'profile')['name'] ?? null;
            if ($waId !== '' && is_string($name)) {
                $names[$waId] = $name;
            }
        }

        $out = [];
        foreach ($this->arr($value, 'messages') as $msg) {
            $from = (string) ($msg['from'] ?? '');
            $id = (string) ($msg['id'] ?? '');
            $type = (string) ($msg['type'] ?? 'other');
            if ($from === '' || $id === '') {
                continue;
            }

            $out[] = new NormalizedMessage(
                provider: 'whatsapp',
                channelExternalId: $channelId,
                conversationExternalId: $from,
                contactName: $names[$from] ?? null,
                contactExternalId: $from,
                contactAvatarUrl: null,
                messageExternalId: $id,
                type: $this->normalizeType($type),
                body: $this->whatsappBody($msg, $type),
                attachments: $this->whatsappAttachments($msg, $type),
                providerTimestamp: $this->tsFromSeconds($msg['timestamp'] ?? null),
            );
        }

        return $out;
    }

    /**
     * Eco de coexistencia: cada echo trae `from` (el negocio) y `to` (el cliente). La
     * contraparte de la conversación es el CLIENTE (`to`). Se ingiere como saliente 'app'.
     * revoke/edit quedan fuera de alcance por ahora (se ignoran).
     *
     * @param  array<string, mixed>  $value
     * @return array<int, NormalizedMessage>
     */
    private function whatsappEchoes(array $value, string $channelId): array
    {
        $out = [];
        foreach ($this->arr($value, 'message_echoes') as $echo) {
            $to = (string) ($echo['to'] ?? '');
            $id = (string) ($echo['id'] ?? '');
            $type = (string) ($echo['type'] ?? 'other');
            if ($to === '' || $id === '' || in_array($type, ['revoke', 'edit'], true)) {
                continue;
            }

            $out[] = new NormalizedMessage(
                provider: 'whatsapp',
                channelExternalId: $channelId,
                conversationExternalId: $to,
                contactName: null,
                contactExternalId: $to,
                contactAvatarUrl: null,
                messageExternalId: $id,
                type: $this->normalizeType($type),
                body: $this->whatsappBody($echo, $type),
                attachments: $this->whatsappAttachments($echo, $type),
                providerTimestamp: $this->tsFromSeconds($echo['timestamp'] ?? null),
                direction: 'outbound',
                senderType: 'app',
            );
        }

        return $out;
    }

    /**
     * Messenger (object 'page') e Instagram (object 'instagram') comparten entry[].messaging[].
     * Se ignoran echoes (is_echo), entregas/lecturas y postbacks; feed/comentarios llegan por
     * entry[].changes[] (no messaging) → se ignoran.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, NormalizedMessage>
     */
    private function fromMessaging(string $provider, array $payload): array
    {
        $out = [];

        foreach ($this->arr($payload, 'entry') as $entry) {
            $channelId = (string) ($entry['id'] ?? '');

            foreach ($this->arr($entry, 'messaging') as $event) {
                $message = is_array($event['message'] ?? null) ? $event['message'] : null;
                if ($message === null || ($message['is_echo'] ?? false) === true) {
                    continue; // no es un mensaje entrante de usuario (echo/postback/delivery/read).
                }

                $sender = (string) ($this->obj($event, 'sender')['id'] ?? '');
                $mid = (string) ($message['mid'] ?? '');
                if ($sender === '' || $mid === '' || $channelId === '') {
                    continue;
                }

                $attachments = $this->messagingAttachments($message);
                $text = isset($message['text']) ? (string) $message['text'] : null;

                $out[] = new NormalizedMessage(
                    provider: $provider,
                    channelExternalId: $channelId,
                    conversationExternalId: $sender,
                    contactName: null, // PSID/IGSID no traen nombre; se enriquece luego vía Graph.
                    contactExternalId: $sender,
                    contactAvatarUrl: null,
                    messageExternalId: $mid,
                    type: $text !== null ? 'text' : ($attachments[0]['type'] ?? 'other'),
                    body: $text,
                    attachments: $attachments === [] ? null : $attachments,
                    providerTimestamp: $this->tsFromMillis($event['timestamp'] ?? null),
                );
            }
        }

        return $out;
    }

    private function whatsappBody(mixed $msg, string $type): ?string
    {
        if (! is_array($msg)) {
            return null;
        }

        return match ($type) {
            'text' => isset($msg['text']['body']) ? (string) $msg['text']['body'] : null,
            'button' => isset($msg['button']['text']) ? (string) $msg['button']['text'] : null,
            'image', 'video', 'document', 'audio' => isset($msg[$type]['caption']) ? (string) $msg[$type]['caption'] : null,
            default => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function whatsappAttachments(mixed $msg, string $type): ?array
    {
        if (! is_array($msg) || ! in_array($type, ['image', 'video', 'audio', 'document', 'sticker'], true)) {
            return null;
        }
        $media = is_array($msg[$type] ?? null) ? $msg[$type] : [];

        // WhatsApp entrega un media id (no URL directa); se resuelve vía Graph al responder.
        return [[
            'type' => $this->normalizeType($type),
            'url' => isset($media['id']) ? (string) $media['id'] : null,
            'mime' => isset($media['mime_type']) ? (string) $media['mime_type'] : null,
        ]];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<int, array<string, mixed>>
     */
    private function messagingAttachments(array $message): array
    {
        $out = [];
        foreach ($this->arr($message, 'attachments') as $att) {
            $payload = is_array($att['payload'] ?? null) ? $att['payload'] : [];
            $out[] = [
                'type' => $this->normalizeType((string) ($att['type'] ?? 'other')),
                'url' => isset($payload['url']) ? (string) $payload['url'] : null,
                'mime' => null,
            ];
        }

        return $out;
    }

    private function normalizeType(string $type): string
    {
        return match ($type) {
            'text', 'image', 'audio', 'video', 'document', 'sticker' => $type,
            'file' => 'document',
            default => 'other',
        };
    }

    private function tsFromSeconds(mixed $ts): ?CarbonImmutable
    {
        return is_numeric($ts) ? CarbonImmutable::createFromTimestamp((int) $ts) : null;
    }

    private function tsFromMillis(mixed $ts): ?CarbonImmutable
    {
        return is_numeric($ts) ? CarbonImmutable::createFromTimestampMs((int) $ts) : null;
    }

    /**
     * Devuelve $source[$key] como lista de arrays (o vacío si no es iterable).
     *
     * @param  array<string, mixed>  $source
     * @return array<int, array<string, mixed>>
     */
    private function arr(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * Devuelve $source[$key] como array asociativo tal cual (sub-objeto: metadata, profile,
     * sender…), o vacío si no lo es.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function obj(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
