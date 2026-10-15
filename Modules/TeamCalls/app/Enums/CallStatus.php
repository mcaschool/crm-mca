<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Enums;

/**
 * Estado GLOBAL de la llamada (el de cada persona es ParticipantState).
 *
 *  initiating → ringing → connecting → active → ended
 *  ringing → declined | cancelled | missed      (nadie llegó a contestar)
 *  cualquier estado vivo → failed               (fallo del sistema o de conexión)
 */
enum CallStatus: string
{
    case Initiating = 'initiating';
    case Ringing = 'ringing';
    case Connecting = 'connecting';
    case Active = 'active';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Missed = 'missed';
    case Ended = 'ended';
    case Failed = 'failed';

    /** @return list<self> */
    public static function live(): array
    {
        return [self::Initiating, self::Ringing, self::Connecting, self::Active];
    }

    public function isLive(): bool
    {
        return in_array($this, self::live(), true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isLive();
    }
}
