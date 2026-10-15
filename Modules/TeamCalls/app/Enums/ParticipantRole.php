<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Enums;

/** host = quien inicia la llamada (controles de anfitrión); member = resto de participantes. */
enum ParticipantRole: string
{
    case Host = 'host';
    case Member = 'member';
}
