<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Events;

/** La llamada terminó; data.status indica cómo (ended, declined, cancelled, missed, failed). */
final class CallEnded extends CallDomainEvent
{
    public static function type(): string
    {
        return 'call.ended';
    }
}
