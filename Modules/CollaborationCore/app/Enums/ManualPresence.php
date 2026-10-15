<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Enums;

enum ManualPresence: string
{
    case Available = 'available';
    case Busy = 'busy';
    case Away = 'away';
    case Offline = 'offline';
}
