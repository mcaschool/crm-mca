<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Enums;

enum ParticipantState: string
{
    case Ringing = 'ringing';
    case Accepted = 'accepted';
    case Joined = 'joined';
    case Left = 'left';
    case Declined = 'declined';
    case Missed = 'missed';
    case Cancelled = 'cancelled';
}
