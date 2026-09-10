<?php

declare(strict_types=1);

namespace Modules\Social\Services;

/**
 * Actualización de ESTADO de un mensaje ya enviado (WhatsApp Cloud API, value.statuses).
 * No es un mensaje: solo referencia por wamid a un SocialMessage existente y trae el
 * nuevo estado reportado por Meta (sent/delivered/read/failed).
 */
final readonly class NormalizedStatus
{
    /**
     * @param  'sent'|'delivered'|'read'|'failed'  $status
     */
    public function __construct(
        public string $provider,
        public string $channelExternalId,
        public string $messageExternalId,
        public string $status,
        public ?string $errorMessage = null,
    ) {}
}
