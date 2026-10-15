<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Enums;

/** 1:1 (dos personas) o grupal (más de dos, ad hoc o desde un grupo/canal). */
enum CallScope: string
{
    case Direct = 'direct';
    case Group = 'group';
}
