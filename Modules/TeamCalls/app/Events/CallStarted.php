<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Events;

/** Llamada iniciada: ya suena para los invitados. */
final class CallStarted extends CallDomainEvent
{
    public static function type(): string
    {
        return 'call.started';
    }
}
