<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;

/**
 * Completa el perfil (nombre y foto) de la conversación de Messenger/Instagram con lo que Meta dé,
 * campo a campo: falta el nombre, la foto o ambos. Usado al recibir un mensaje y por la
 * recuperación programada de conversaciones antiguas (social:refresh-contact-profiles).
 *
 *  - Nunca sustituye un dato válido por null: el nombre conocido se conserva y la foto solo se
 *    renueva con otra URL válida (las de Meta caducan).
 *  - Idempotente y con espera entre consultas (profile_checked_at): un perfil completo no se vuelve
 *    a consultar; uno que Meta no permite (permiso, cuenta sin perfil) se reintenta a los 7 días; un
 *    fallo transitorio, con espera creciente (15 min, 30 min… hasta 24 h). Así ni los mensajes
 *    seguidos ni la recuperación multiplican las llamadas a Meta.
 */
final class ContactProfileUpdater
{
    public const PROVIDERS = ['messenger', 'instagram'];

    private const UNAVAILABLE_RETRY_DAYS = 7;

    private const ERROR_RETRY_MINUTES = 15;

    private const ERROR_RETRY_MAX_MINUTES = 1440;

    public function __construct(private readonly ContactProfileResolver $resolver) {}

    /** ¿Le falta algo y toca consultar ya a Meta? */
    public function due(SocialConversation $conversation): bool
    {
        if (! in_array($conversation->provider, self::PROVIDERS, true) || (string) $conversation->contact_external_id === '') {
            return false;
        }
        if ($this->filled($conversation->contact_name) && $this->filled($conversation->contact_avatar_url)) {
            return false;
        }
        $checked = $conversation->profile_checked_at;
        if ($checked === null) {
            return true;
        }
        $waitMinutes = $conversation->profile_status === 'error'
            ? min(self::ERROR_RETRY_MAX_MINUTES, self::ERROR_RETRY_MINUTES * 2 ** max(0, min(10, $conversation->profile_attempts - 1)))
            : self::UNAVAILABLE_RETRY_DAYS * 1440;

        return $checked->lte(now()->subMinutes($waitMinutes));
    }

    /**
     * Consulta a Meta (si toca, o siempre con $force) y guarda lo obtenido. Devuelve true si se
     * consultó. Nunca lanza por un fallo de Meta (el resolver ya es best-effort).
     */
    public function refresh(SocialConversation $conversation, SocialChannel $channel, bool $force = false): bool
    {
        $resolvable = in_array($conversation->provider, self::PROVIDERS, true) && (string) $conversation->contact_external_id !== '';
        if (! $resolvable || (! $force && ! $this->due($conversation))) {
            return false;
        }

        $profile = $this->resolver->resolve($channel, $conversation->provider, (string) $conversation->contact_external_id);

        if (! $this->filled($conversation->contact_name) && $profile->name !== null) {
            $conversation->contact_name = $profile->name;
        }
        if ($profile->avatar !== null) {
            $conversation->contact_avatar_url = $profile->avatar; // URL nueva: las de Meta caducan
        }
        $conversation->contact_first_name = $profile->firstName ?? $conversation->contact_first_name;
        $conversation->contact_last_name = $profile->lastName ?? $conversation->contact_last_name;

        $hasName = $this->filled($conversation->contact_name);
        $hasAvatar = $this->filled($conversation->contact_avatar_url);
        $conversation->profile_status = match (true) {
            $hasName && $hasAvatar => 'complete',
            $profile->error !== null && ! $profile->permanent => 'error',
            $hasName || $hasAvatar => 'partial',
            default => 'unavailable',
        };
        $conversation->profile_attempts = $conversation->profile_status === 'complete' ? 0 : min(65000, $conversation->profile_attempts + 1);
        $conversation->profile_error = $profile->error;
        $conversation->profile_checked_at = now();
        $conversation->save();

        return true;
    }

    private function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
