<?php

declare(strict_types=1);

namespace Modules\Social\Exceptions;

use Modules\Institutions\Models\Bot;
use Modules\Social\Models\SocialChannel;
use RuntimeException;

/**
 * La cuenta ya la atiende otro asesor (o cambió mientras se decidía), o el despacho automático no
 * está listo. El mensaje es apto para mostrarlo en el panel.
 */
final class AdvisorAssignmentConflict extends RuntimeException
{
    public ?string $currentAdvisor = null;

    public static function with(SocialChannel $channel, ?Bot $current): self
    {
        $name = $current->assistant_name ?? __('otro asesor');
        $e = new self(__(':channel ya la atiende :advisor. Para cambiarlo, usa «Reasignar a este asesor».', [
            'channel' => $channel->display_name,
            'advisor' => $name,
        ]));
        $e->currentAdvisor = $name;

        return $e;
    }

    public static function notReady(string $reason): self
    {
        return new self($reason);
    }
}
