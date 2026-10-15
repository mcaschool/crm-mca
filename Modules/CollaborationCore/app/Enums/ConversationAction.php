<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Enums;

enum ConversationAction: string
{
    case View = 'view';
    case StartCall = 'start_call';
    case InviteToCall = 'invite_to_call';
    case JoinCall = 'join_call';
}
