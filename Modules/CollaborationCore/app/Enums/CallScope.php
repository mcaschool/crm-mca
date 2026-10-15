<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Enums;

enum CallScope: string
{
    case Direct = 'direct';
    case Group = 'group';
}
