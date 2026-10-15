<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Enums;

enum DeviceStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
