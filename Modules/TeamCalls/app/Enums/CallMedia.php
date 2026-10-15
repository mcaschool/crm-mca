<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Enums;

enum CallMedia: string
{
    case Audio = 'audio';
    case Video = 'video';
}
