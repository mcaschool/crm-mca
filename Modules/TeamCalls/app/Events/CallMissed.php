<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Events;

/** Una persona no contestó a tiempo (llamada perdida para ella). */
final class CallMissed extends CallDomainEvent
{
    public static function type(): string
    {
        return 'call.missed';
    }
}
