<?php

declare(strict_types=1);

namespace Modules\Social\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Evento sin contenido ni PII: notifica que un mensaje social nuevo quedo confirmado en BD. */
final readonly class SocialMessageStored
{
    use Dispatchable;

    public function __construct(
        public int $institutionId,
        public int $conversationId,
        public int $messageId,
        public string $direction,
    ) {}
}
