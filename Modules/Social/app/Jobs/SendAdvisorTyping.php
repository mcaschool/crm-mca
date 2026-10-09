<?php

declare(strict_types=1);

namespace Modules\Social\Jobs;

use Modules\Core\Jobs\TenantAwareJob;
use Modules\Social\Services\AdvisorTypingIndicator;

/**
 * «Escribiendo» nativo del canal al recibir un mensaje que el asesor va a contestar. Se despacha
 * DESPUÉS de responder al webhook (dispatchAfterResponse): no retrasa el 200 a Meta, no pasa por la
 * cola (llegaría tarde: el worker corre cada minuto) y no ocupa el worker de respuestas.
 */
final class SendAdvisorTyping extends TenantAwareJob
{
    public function __construct(
        public int $messageId,
        ?int $institutionId = null,
    ) {
        parent::__construct($institutionId);
    }

    protected function handleForInstitution(): void
    {
        app(AdvisorTypingIndicator::class)->forMessage($this->messageId);
    }
}
