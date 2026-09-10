<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Carbon\CarbonImmutable;

/**
 * Formato INTERNO común (raw Meta ya normalizado). Contrato que consume el servicio núcleo,
 * idéntico sea cual sea el proveedor de origen.
 *
 * Soporta entrantes (direction='inbound', senderType='contact') y salientes originados fuera
 * del panel — p. ej. el eco de coexistencia de WhatsApp, un mensaje que el negocio envía
 * desde la app del teléfono (direction='outbound', senderType='app').
 */
final readonly class NormalizedMessage
{
    /**
     * @param  'inbound'|'outbound'  $direction
     * @param  'contact'|'agent'|'app'  $senderType
     * @param  array<int, array<string, mixed>>|null  $attachments
     */
    public function __construct(
        public string $provider,
        public string $channelExternalId,
        public string $conversationExternalId,
        public ?string $contactName,
        public ?string $contactExternalId,
        public ?string $contactAvatarUrl,
        public string $messageExternalId,
        public string $type,
        public ?string $body,
        public ?array $attachments,
        public ?CarbonImmutable $providerTimestamp,
        public string $direction = 'inbound',
        public string $senderType = 'contact',
        // Mensaje del BACKFILL de historial (Coexistence): se ingiere igual, pero sin
        // subir unread y sin retroceder preview/last_message_at de la conversación.
        public bool $fromHistory = false,
    ) {}
}
