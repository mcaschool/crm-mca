<?php

declare(strict_types=1);

namespace Modules\Social\Jobs;

use Illuminate\Support\Facades\Log;
use Modules\Core\Jobs\TenantAwareJob;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\WhatsAppMediaService;
use Throwable;

/**
 * Descarga los adjuntos ENTRANTES de un mensaje de WhatsApp FUERA del ciclo crítico del
 * webhook: la ingesta lo despacha con dispatchAfterResponse(), así Meta recibe su 200
 * de inmediato y la descarga (Graph + URL temporal + Bearer) ocurre tras enviar la
 * respuesta, en el mismo proceso, sin worker ni infraestructura nueva. Si algún día se
 * levanta un worker real (database queue ya configurada), basta despachar con dispatch().
 *
 * IDEMPOTENTE: solo procesa attachments con provider_media_id y SIN storage_path; un
 * reintento del webhook ni duplica el mensaje (ingesta) ni re-descarga (este filtro).
 * Cualquier fallo conserva el provider_media_id, deja log seguro (jamás token ni URL
 * temporal) y NUNCA borra el mensaje.
 */
final class ProcessWhatsAppInboundMedia extends TenantAwareJob
{
    public function __construct(
        public int $channelId,
        public int $messageId,
        ?int $institutionId = null,
    ) {
        parent::__construct($institutionId);
    }

    protected function handleForInstitution(): void
    {
        try {
            $channel = SocialChannel::query()->find($this->channelId);
            $message = SocialMessage::query()->find($this->messageId);
            if ($channel === null || $message === null || ! is_array($message->attachments)) {
                return;
            }

            $media = app(WhatsAppMediaService::class);
            $attachments = $message->attachments;
            $changed = false;

            foreach ($attachments as $i => $att) {
                if (! is_array($att)) {
                    continue;
                }
                $mediaId = (string) ($att['provider_media_id'] ?? '');
                if ($mediaId === '' || ! empty($att['storage_path'])) {
                    continue; // sin media que resolver, o ya descargado (idempotencia)
                }

                $meta = $media->fetchInbound(
                    $channel,
                    $mediaId,
                    (string) ($att['type'] ?? 'document'),
                    isset($att['filename']) && is_string($att['filename']) ? $att['filename'] : null,
                );
                if ($meta === null) {
                    continue; // best-effort: queda el provider_media_id para un reintento futuro
                }

                $attachments[$i] = array_merge($att, $meta);
                $changed = true;
            }

            if ($changed) {
                $message->attachments = $attachments;
                $message->save();
            }
        } catch (Throwable $e) {
            // Solo información segura: nunca token, Authorization ni la URL temporal de Meta.
            Log::warning('social.wa.media.job: no se pudo procesar el adjunto entrante', [
                'message_id' => $this->messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
