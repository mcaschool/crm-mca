<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Events;

/** Una persona se conectó a la sala. */
final class ParticipantJoined extends CallDomainEvent
{
    public static function type(): string
    {
        return 'call.participant.joined';
    }
}
