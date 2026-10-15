<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Enums;

enum AuthenticationLevel: string
{
    case Password = 'password';
    case Mfa = 'mfa';
}
