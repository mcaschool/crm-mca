<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Events;

/** Una persona salió de la llamada. */
final class ParticipantLeft extends CallDomainEvent
{
    public static function type(): string
    {
        return 'call.participant.left';
    }
}
