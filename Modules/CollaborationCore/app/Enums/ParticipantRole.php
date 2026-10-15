<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Enums;

enum ParticipantRole: string
{
    case Host = 'host';
    case Member = 'member';
}
