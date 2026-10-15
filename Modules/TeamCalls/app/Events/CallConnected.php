<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Events;

/** La llamada pasó a activa (al menos dos personas conectadas). */
final class CallConnected extends CallDomainEvent
{
    public static function type(): string
    {
        return 'call.connected';
    }
}
