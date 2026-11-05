<?php

declare(strict_types=1);

namespace Modules\FollowUp\Support;

use Modules\Institutions\Models\Bot;
use Modules\Social\Models\SocialChannel;

final class AdvisorChannels
{
    /** @param array<int,string> $allowedProviders */
    public static function restriction(SocialChannel $channel, Bot $bot, array $allowedProviders): ?string
    {
        if ((int) $channel->institution_id !== (int) $bot->institution_id || (int) $channel->advisor_bot_id !== (int) $bot->id) {
            return 'Asignado a otro asesor o sin asignación.';
        }
        if ($bot->status !== 'active') {
            return 'El asesor está inactivo.';
        }
        if ($channel->provider !== 'whatsapp') {
            return 'El contrato actual de este canal no permite seguimiento proactivo.';
        }
        if (! in_array($channel->provider, $allowedProviders, true)) {
            return 'Excluido por la política institucional.';
        }
        if (! $channel->advisor_enabled) {
            return 'La atención del asesor está deshabilitada en este canal.';
        }
        if (! $channel->automationCanSend()) {
            return 'Canal desconectado, inactivo o sin configuración de envío válida.';
        }

        return null;
    }
}
