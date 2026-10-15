<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Enums;

enum DevicePlatform: string
{
    case Web = 'web';
    case Android = 'android';
    case Ios = 'ios';
}
