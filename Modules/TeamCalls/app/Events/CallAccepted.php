<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Events;

/** Una persona contestó (desde un dispositivo concreto). */
final class CallAccepted extends CallDomainEvent
{
    public static function type(): string
    {
        return 'call.accepted';
    }
}
