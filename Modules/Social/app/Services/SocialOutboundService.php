<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Social\Exceptions\UnsupportedSocialProviderException;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Models\SocialWhatsAppTemplate;
use RuntimeException;

/**
 * Orquesta el envío saliente desde el panel: persiste el mensaje, llama a Meta y refleja el
 * resultado. Trabaja dentro del contexto de institución ya fijado por el panel (el trait
 * sella institution_id). Instagram y Messenger salen por MetaMessageSender (me/messages);
 * WhatsApp por WhatsAppMessageSender (canal propio, /{PHONE_NUMBER_ID}/messages), con
 * soporte de adjuntos vía la Media API (subida por media id, nunca por URL pública).
 */
final class SocialOutboundService
{
    /** Proveedores con salida habilitada. */
    public const SENDABLE = ['instagram', 'messenger', 'whatsapp'];

    public function __construct(
        private readonly MetaMessageSender $sender,
        private readonly WhatsAppMessageSender $whatsapp,
        private readonly WhatsAppMediaService $media,
    ) {}

    public function send(SocialConversation $conversation, string $text, User $user): SocialMessage
    {
        if (! in_array($conversation->provider, self::SENDABLE, true)) {
            throw UnsupportedSocialProviderException::for($conversation->provider);
        }

        $channel = $conversation->channel;

        // 1) Persistir el saliente en 'pending' (aparece de inmediato en el hilo).
        $message = $this->newOutbound($conversation, 'text', $text, null, $user);

        // 2) Enviar a Meta y 3) reflejar el resultado.
        $result = match (true) {
            $channel === null => SendResult::failed('La conversación no tiene canal asociado.'),
            $conversation->provider === 'whatsapp' => $this->whatsapp->sendText($channel, $conversation, $text),
            default => $this->sender->sendText($channel, $conversation, $text),
        };

        $this->applyResult($message, $result);
        $this->touchConversation($conversation, $text, $message);

        return $message;
    }

    /**
     * Envía un ADJUNTO por WhatsApp: clasifica por MIME real, guarda la copia privada para
     * la bandeja, sube el binario a la Media API y envía por media id ($caption opcional).
     * Lanza RuntimeException con mensaje apto para el usuario si el archivo no es enviable.
     */
    public function sendWhatsAppMedia(SocialConversation $conversation, UploadedFile $file, string $caption, User $user): SocialMessage
    {
        if ($conversation->provider !== 'whatsapp') {
            throw UnsupportedSocialProviderException::for($conversation->provider);
        }

        $channel = $conversation->channel;
        [$type, $mime] = $this->media->classifyUpload($file); // RuntimeException si no es enviable

        $stored = $this->media->storeUpload((int) $conversation->institution_id, $file, $mime);
        $caption = trim($caption);

        $attachment = [
            'type' => $type,
            'mime' => $mime,
            'storage_path' => $stored['storage_path'],
            'filename' => $stored['filename'],
            'size' => $stored['size'],
        ];
        $message = $this->newOutbound($conversation, $type, $caption !== '' ? $caption : null, [$attachment], $user);

        $result = SendResult::failed('La conversación no tiene canal asociado.');
        if ($channel !== null) {
            $mediaId = $this->media->upload(
                $channel,
                Storage::disk('local')->path($stored['storage_path']),
                $mime,
                $stored['filename'],
            );

            if ($mediaId === null) {
                $result = SendResult::failed('No se pudo subir el archivo a WhatsApp. Inténtalo de nuevo.');
            } else {
                $attachment['provider_media_id'] = $mediaId;
                $message->attachments = [$attachment];
                $result = $this->whatsapp->sendMedia(
                    $channel,
                    $conversation,
                    $type,
                    $mediaId,
                    $caption !== '' ? $caption : null,
                    $type === 'document' ? $stored['filename'] : null,
                );
            }
        }

        $this->applyResult($message, $result);
        $this->touchConversation($conversation, $caption !== '' ? $caption : '['.$type.']', $message);

        return $message;
    }

    /**
     * Envía una PLANTILLA aprobada por WhatsApp (fuera o dentro de la ventana de 24h).
     * $parameters son los valores posicionales [1 => 'Carlos', 2 => 'MBA', …]. En el
     * mensaje local se guarda el texto RENDERIZADO (para la burbuja) y metadata NO
     * sensible de la plantilla (id/nombre/idioma/parámetros) — nunca tokens ni payloads.
     *
     * @param  array<int, string>  $parameters
     */
    public function sendWhatsAppTemplate(SocialConversation $conversation, SocialWhatsAppTemplate $template, array $parameters, User $user): SocialMessage
    {
        if ($conversation->provider !== 'whatsapp') {
            throw UnsupportedSocialProviderException::for($conversation->provider);
        }
        $channel = $conversation->channel;
        if ($channel === null || $template->social_channel_id !== $channel->id) {
            throw new RuntimeException(__('La plantilla no pertenece al canal de esta conversación.'));
        }
        if (! $template->isApproved()) {
            throw new RuntimeException(__('Solo se pueden enviar plantillas APROBADAS por WhatsApp.'));
        }
        if ($template->hasMediaHeader()) {
            throw new RuntimeException(__('El envío de plantillas con encabezado de imagen/video/documento desde la bandeja llegará después.'));
        }

        $missing = array_diff($template->positionalVariables(), array_keys(array_filter($parameters, fn ($v) => trim((string) $v) !== '')));
        if ($missing !== []) {
            throw new RuntimeException(__('Completa el valor de todas las variables de la plantilla.'));
        }

        // Texto renderizado para la burbuja de la bandeja (sustituye {{n}} por su valor).
        $bodyText = (string) ($template->component('BODY')['text'] ?? '');
        $rendered = preg_replace_callback(
            '/\{\{(\d+)\}\}/',
            fn (array $m): string => (string) ($parameters[(int) $m[1]] ?? $m[0]),
            $bodyText,
        ) ?? $bodyText;

        $meta = [[
            'type' => 'template',
            'template_id' => $template->meta_template_id,
            'template_name' => $template->name,
            'template_language' => $template->language,
            'template_parameters' => array_map(strval(...), $parameters),
        ]];
        $message = $this->newOutbound($conversation, 'template', $rendered, $meta, $user);

        $result = $this->whatsapp->sendTemplate(
            $channel,
            $conversation,
            $template->name,
            $template->language,
            $this->templateComponents($template, $parameters),
        );

        $this->applyResult($message, $result);
        $this->touchConversation($conversation, $rendered, $message);

        return $message;
    }

    /**
     * Componentes con parámetros para el envío (body y, si aplica, header de texto).
     *
     * @param  array<int, string>  $parameters
     * @return array<int, array<string, mixed>>
     */
    private function templateComponents(SocialWhatsAppTemplate $template, array $parameters): array
    {
        $validator = new WhatsAppTemplateValidator;
        $components = [];

        $header = $template->component('HEADER');
        if ($header !== null && strtoupper((string) ($header['format'] ?? 'TEXT')) === 'TEXT') {
            $headerVars = $validator->variables((string) ($header['text'] ?? ''));
            if ($headerVars !== []) {
                $components[] = [
                    'type' => 'header',
                    'parameters' => [['type' => 'text', 'text' => (string) ($parameters[$headerVars[0]] ?? '')]],
                ];
            }
        }

        $bodyVars = $validator->variables((string) ($template->component('BODY')['text'] ?? ''));
        if ($bodyVars !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn (int $n): array => ['type' => 'text', 'text' => (string) ($parameters[$n] ?? '')],
                    $bodyVars,
                ),
            ];
        }

        return $components;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $attachments
     */
    private function newOutbound(SocialConversation $conversation, string $type, ?string $body, ?array $attachments, User $user): SocialMessage
    {
        $message = new SocialMessage;
        $message->social_conversation_id = $conversation->id;
        $message->external_message_id = null;
        $message->direction = 'outbound';
        $message->type = $type;
        $message->body = $body;
        $message->attachments = $attachments;
        $message->status = 'pending';
        $message->sender_type = 'agent';
        $message->sent_by = $user->id;
        $message->provider_timestamp = now();
        $message->save();

        return $message;
    }

    private function applyResult(SocialMessage $message, SendResult $result): void
    {
        $message->status = $result->status;                 // sent | failed | failed_window
        if ($result->externalId !== null && $result->externalId !== '') {
            // El id de Meta (wamid en WhatsApp) permite deduplicar echoes y, en WhatsApp,
            // reconciliar los estados sent/delivered/read/failed del webhook.
            $message->external_message_id = $result->externalId;
        }
        $message->save();
    }

    private function touchConversation(SocialConversation $conversation, string $preview, SocialMessage $message): void
    {
        // Conversación: preview + last_message_at; unread_count NO cambia (es saliente).
        $conversation->last_message_preview = Str::limit($preview, 140);
        $conversation->last_message_at = $message->provider_timestamp;
        $conversation->save();
    }
}
