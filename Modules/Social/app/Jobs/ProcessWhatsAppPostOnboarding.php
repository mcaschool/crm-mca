<?php

declare(strict_types=1);

namespace Modules\Social\Jobs;

use Illuminate\Support\Facades\Log;
use Modules\Core\Jobs\TenantAwareJob;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\WhatsAppCoexistenceService;
use Throwable;

/**
 * Post-onboarding de Coexistence: tras un Embedded Signup exitoso dispara las
 * sincronizaciones FUERA del ciclo de la respuesta (dispatchAfterResponse, el mismo
 * patrón que ProcessWhatsAppInboundMedia): primero CONTACTOS, después HISTORIAL.
 *
 * Cada sync es independiente y best-effort:
 *  - la lógica de "solo una vez" y de marcar SOLO tras éxito vive en el servicio
 *    (un fallo transitorio deja retry disponible; nunca invalida el canal);
 *  - history 'declined' (2593109) NO es un error: el canal sigue operativo;
 *  - ningún fallo aquí revierte el onboarding: el canal ya quedó conectado.
 */
final class ProcessWhatsAppPostOnboarding extends TenantAwareJob
{
    public function __construct(
        public int $channelId,
        ?int $institutionId = null,
    ) {
        parent::__construct($institutionId);
    }

    protected function handleForInstitution(): void
    {
        $channel = SocialChannel::query()->find($this->channelId);
        if ($channel === null || $channel->provider !== 'whatsapp') {
            return;
        }

        $coexistence = app(WhatsAppCoexistenceService::class);

        try {
            $contacts = $coexistence->startContactsSync($channel);
            Log::info('social.wa.coex: contact sync post-onboarding', ['channel_id' => $channel->id, 'result' => $contacts]);
        } catch (Throwable $e) {
            Log::warning('social.wa.coex: contact sync post-onboarding falló', ['channel_id' => $channel->id, 'error' => $e->getMessage()]);
        }

        try {
            $history = $coexistence->startHistorySync($channel->refresh());
            Log::info('social.wa.coex: history sync post-onboarding', ['channel_id' => $channel->id, 'result' => $history]);
        } catch (Throwable $e) {
            Log::warning('social.wa.coex: history sync post-onboarding falló', ['channel_id' => $channel->id, 'error' => $e->getMessage()]);
        }
    }
}
