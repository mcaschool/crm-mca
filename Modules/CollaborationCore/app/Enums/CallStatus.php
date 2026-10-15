<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Enums;

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
}
