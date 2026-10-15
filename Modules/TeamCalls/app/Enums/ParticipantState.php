<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Enums;

/**
 * Estado INDIVIDUAL de cada participante (invitación y participación):
 *
 *  ringing  → accepted (contestó) | declined | missed (no contestó a tiempo) | cancelled (se retiró la llamada)
 *  accepted → joined (conectado a la sala) | left
 *  joined   → left
 *  left / declined / missed → joined (se incorpora después a una llamada que sigue viva)
 */
enum ParticipantState: string
{
    case Ringing = 'ringing';
    case Accepted = 'accepted';
    case Joined = 'joined';
    case Left = 'left';
    case Declined = 'declined';
    case Missed = 'missed';
    case Cancelled = 'cancelled';

    /** En la llamada (contestó o está conectado). */
    public function isIn(): bool
    {
        return $this === self::Accepted || $this === self::Joined;
    }
}
