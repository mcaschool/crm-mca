<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Social\Models\SocialChannel;
use Throwable;

/**
 * Resuelve el nombre y la foto de un contacto (Messenger PSID / Instagram IGSID) contra la
 * Graph API de Facebook, usando el Page Access Token del canal (el mismo del envío). Host
 * graph.facebook.com (coherente con mensajería/publicador; el token es EAA).
 *
 *  - Messenger: GET /{PSID}?fields=name,profile_pic
 *  - Instagram: GET /{IGSID}?fields=name,username,profile_pic  (username como fallback de nombre)
 *
 * Es BEST-EFFORT: ante cualquier fallo (red, no-200, campos ausentes) devuelve nulls y NUNCA
 * lanza, para que la ingesta del mensaje no se bloquee por no resolver el perfil. WhatsApp no
 * se resuelve aquí (su nombre llega en el propio webhook).
 */
final class ContactProfileResolver
{
    private const TIMEOUT_SECONDS = 4;

    /**
     * @return array{name: string|null, avatar: string|null}
     */
    public function resolve(SocialChannel $channel, string $provider, string $externalId): array
    {
        $none = ['name' => null, 'avatar' => null];

        if (! in_array($provider, ['messenger', 'instagram'], true) || $externalId === '') {
            return $none;
        }

        $token = (string) ($channel->credentials['token'] ?? '');
        if ($token === '') {
            return $none;
        }

        $version = (string) config('social.graph_version', 'v26.0');
        // Instagram añade 'username' como fallback de nombre. La foto es 'profile_pic' en ambos.
        $fields = $provider === 'instagram' ? 'name,username,profile_pic' : 'name,profile_pic';

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->get("https://graph.facebook.com/{$version}/{$externalId}", ['fields' => $fields]);
        } catch (Throwable $e) {
            Log::info('social.contact.resolve: error de red', ['provider' => $provider, 'error' => $e->getMessage()]);

            return $none;
        }

        if (! $response->successful()) {
            Log::info('social.contact.resolve: Meta no resolvió el perfil', ['provider' => $provider, 'status' => $response->status()]);

            return $none;
        }

        $name = $response->json('name');
        $name = is_string($name) && trim($name) !== '' ? trim($name) : null;

        // Instagram: si no hay 'name', usar '@username' como nombre a mostrar.
        if ($name === null && $provider === 'instagram') {
            $username = $response->json('username');
            $name = is_string($username) && trim($username) !== '' ? '@'.trim($username) : null;
        }

        $avatar = $response->json('profile_pic');
        $avatar = is_string($avatar) && $avatar !== '' ? $avatar : null;

        return ['name' => $name, 'avatar' => $avatar];
    }
}
