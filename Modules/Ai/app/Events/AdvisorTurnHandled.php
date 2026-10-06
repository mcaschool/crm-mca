<?php

declare(strict_types=1);

namespace Modules\Ai\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un turno del asesor pasó por la capa común (AdvisorTurnService), sea cual sea el canal. Sirve
 * de trazabilidad (canal de origen, estado) y para comprobar que todos los canales entran por el
 * mismo contrato. No lleva texto del usuario ni datos personales.
 */
final class AdvisorTurnHandled
{
    use Dispatchable;

    public function __construct(
        public readonly int $institutionId,
        public readonly int $botId,
        public readonly string $channel,
        public readonly int $conversationId,
        public readonly string $status,
        public readonly bool $isTest,
        public readonly string $kind, // open | message
    ) {}
}
