<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Events;

/** Una persona recibe la invitación (suena en sus dispositivos). */
final class CallRinging extends CallDomainEvent
{
    public static function type(): string
    {
        return 'call.ringing';
    }
}
