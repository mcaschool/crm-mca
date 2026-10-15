<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Enums;

enum ConversationType: string
{
    case Direct = 'direct';
    case Group = 'group';
    case Channel = 'channel';
}
