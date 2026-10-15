<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Events;

/** Una persona rechazó la llamada. */
final class CallDeclined extends CallDomainEvent
{
    public static function type(): string
    {
        return 'call.declined';
    }
}
