<?php

declare(strict_types=1);

namespace Modules\Social\Support;

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Models\MetaLeadPage;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialPost;
use Modules\Social\Models\SocialPostTarget;
use Modules\Social\Services\ContactProfileUpdater;
use Modules\Social\Services\SocialPostScheduler;

/**
 * Comprobaciones baratas (1–3 `EXISTS` globales) que el planificador evalúa ANTES de lanzar un
 * proceso propio: si no hay trabajo, el proceso no arranca. Replican las condiciones de entrada de
 * cada comando sin ejecutarlo; el comando sigue siendo quien reclama y procesa de forma atómica.
 */
final class SocialPendingWork
{
    /** Igual que SocialPostScheduler (espera entre reintentos de un contenedor de Instagram). */
    private const RESUME_EVERY_SECONDS = 60;

    /** social:publish-scheduled: vencidas, interrumpidas (> 15 min) o videos de Instagram por reanudar. */
    public static function publishing(): bool
    {
        return self::globally(fn (): bool => SocialPost::query()
            ->where('status', SocialPost::SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->exists()
            || SocialPost::query()
                ->where('status', SocialPost::PREPARING)
                ->where('locked_at', '<', now()->subMinutes(SocialPostScheduler::STALE_MINUTES))
                ->exists()
            || SocialPostTarget::query()
                ->where('network', 'instagram')
                ->where('status', 'processing')
                ->whereNotNull('container_id')
                ->where(fn ($q) => $q->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<=', now()->subSeconds(self::RESUME_EVERY_SECONDS)))
                ->exists());
    }

    /** social:meta-leads-poll: alguna Página con recepción de formularios activa (y sin kill-switch). */
    public static function metaLeadForms(): bool
    {
        if ((bool) config('social.meta.lead_forms_kill_switch', false)) {
            return false;
        }

        return self::globally(fn (): bool => MetaLeadPage::query()
            ->where('receiving_enabled', true)
            ->where('selected', true)
            ->where('available', true)
            ->where('access_status', 'verified')
            ->exists());
    }

    /** social:refresh-contact-profiles: conversaciones de Messenger/Instagram con nombre o foto incompletos. */
    public static function contactProfiles(): bool
    {
        return self::globally(fn (): bool => SocialConversation::query()
            ->whereIn('provider', ContactProfileUpdater::PROVIDERS)
            ->whereNotNull('contact_external_id')
            ->where('contact_external_id', '!=', '')
            ->where(fn ($q) => $q->whereNull('contact_name')->orWhere('contact_name', '')
                ->orWhereNull('contact_avatar_url')->orWhere('contact_avatar_url', ''))
            ->exists());
    }

    private static function globally(callable $callback): bool
    {
        return (bool) app(CurrentInstitution::class)->runGlobally($callback);
    }
}
