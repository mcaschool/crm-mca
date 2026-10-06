<?php

declare(strict_types=1);

namespace Modules\Social\Jobs;

use Modules\Core\Jobs\TenantAwareJob;
use Modules\Social\Services\SocialAdvisorResponder;

/**
 * Respuesta del asesor inteligente a un mensaje entrante de Instagram, Messenger o WhatsApp,
 * FUERA del ciclo del webhook: la ingesta la despacha con dispatchAfterResponse(), así Meta
 * recibe su 200 de inmediato y la espera configurada, la IA y el envío ocurren después, en el
 * mismo proceso y sin worker (como ProcessWhatsAppInboundMedia). Solo se despacha si el canal
 * tiene el asesor activado.
 */
final class RespondWithAdvisor extends TenantAwareJob
{
    public function __construct(
        public int $messageId,
        ?int $institutionId = null,
    ) {
        parent::__construct($institutionId);
    }

    protected function handleForInstitution(): void
    {
        app(SocialAdvisorResponder::class)->respond($this->messageId);
    }
}
