<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Social\Models\SocialChannel;
use Throwable;

/**
 * Resuelve el nombre y la foto de un contacto (Messenger PSID / Instagram IGSID) contra la
 * Graph API de Facebook, usando el Page Access Token del canal (el mismo del envío). Host
 * graph.facebook.com (coherente con mensajería/publicador; el token es EAA).
 *
 *  - Messenger: GET /{PSID}?fields=first_name,last_name,name,profile_pic (User Profile API). Sus
 *    campos exigen la función «Business Asset User Profile Access» con acceso avanzado: sin ella,
 *    Meta solo responde para personas con rol en la Página/app y para el resto devuelve un error
 *    (p. ej. #2018247). En ese caso el NOMBRE se obtiene de la Conversations API
 *    (GET /{page}/conversations?platform=messenger&user_id={PSID}&fields=participants), que solo
 *    requiere pages_messaging + pages_manage_metadata; la foto no tiene alternativa oficial.
 *  - Instagram: GET /{IGSID}?fields=name,username,profile_pic (username como fallback de nombre).
 *
 * Es BEST-EFFORT: ante cualquier fallo devuelve los campos a null y NUNCA lanza, para que la
 * ingesta del mensaje no se bloquee. El fallo se registra con el estado HTTP y el código y
 * subcódigo de Meta (nunca el token, el mensaje de Meta ni datos del contacto). WhatsApp no se
 * resuelve aquí (su nombre llega en el propio webhook).
 */
final class ContactProfileResolver
{
    private const TIMEOUT_SECONDS = 4;

    /**
     * Códigos de Meta que no cambian reintentando enseguida: permiso de la app (#10, #200, #2018247),
     * objeto inexistente o sin permiso (#100), cuenta sin perfil (#2018218) y token (#190).
     */
    private const PERMANENT_CODES = [10, 100, 190, 200, 2018218, 2018247];

    public function resolve(SocialChannel $channel, string $provider, string $externalId): ContactProfile
    {
        if (! in_array($provider, ['messenger', 'instagram'], true) || $externalId === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $externalId) !== 1) {
            return new ContactProfile;
        }

        $token = (string) ($channel->credentials['token'] ?? '');
        if ($token === '') {
            return new ContactProfile(error: 'sin_token', permanent: true);
        }

        $profile = $provider === 'instagram'
            ? $this->instagram($token, $externalId)
            : $this->messenger($token, $channel, $externalId);

        return $profile;
    }

    private function instagram(string $token, string $igsid): ContactProfile
    {
        [$response, $error, $permanent] = $this->get($token, $igsid, ['fields' => 'name,username,profile_pic'], 'instagram', 'profile');
        if ($response === null) {
            return new ContactProfile(error: $error, permanent: $permanent);
        }

        $name = $this->text($response->json('name'));
        // Instagram: si no hay 'name', usar '@username' como nombre a mostrar.
        if ($name === null && ($username = $this->text($response->json('username'))) !== null) {
            $name = '@'.$username;
        }

        return new ContactProfile(name: $name, avatar: $this->url($response->json('profile_pic')));
    }

    private function messenger(string $token, SocialChannel $channel, string $psid): ContactProfile
    {
        [$response, $error, $permanent] = $this->get($token, $psid, ['fields' => 'first_name,last_name,name,profile_pic'], 'messenger', 'profile');

        $first = $response !== null ? $this->text($response->json('first_name')) : null;
        $last = $response !== null ? $this->text($response->json('last_name')) : null;
        $name = $response !== null ? $this->text($response->json('name')) : null;
        $name ??= $first !== null ? trim($first.' '.($last ?? '')) : null;
        $avatar = $response !== null ? $this->url($response->json('profile_pic')) : null;

        // Sin acceso al perfil (o perfil vacío): el nombre del participante de la conversación.
        if ($name === null) {
            [$name, $convError, $convPermanent] = $this->participantName($token, $channel, $psid);
            if ($name === null && $error === null) {
                [$error, $permanent] = [$convError, $convPermanent];
            }
        }

        return new ContactProfile(name: $name, firstName: $first, lastName: $last, avatar: $avatar,
            error: $name === null || $avatar === null ? $error : null, permanent: $permanent);
    }

    /** @return array{0: string|null, 1: string|null, 2: bool} [nombre, error, permanente] */
    private function participantName(string $token, SocialChannel $channel, string $psid): array
    {
        $pageId = (string) $channel->external_id;
        if (preg_match('/^\d{1,30}$/', $pageId) !== 1) {
            return [null, 'sin_pagina', true];
        }
        [$response, $error, $permanent] = $this->get($token, $pageId.'/conversations', [
            'platform' => 'messenger', 'user_id' => $psid, 'fields' => 'participants',
        ], 'messenger', 'conversations');
        if ($response === null) {
            return [null, $error, $permanent];
        }

        foreach ((array) $response->json('data', []) as $conversation) {
            foreach ((array) data_get($conversation, 'participants.data', []) as $p) {
                if (is_array($p) && (string) ($p['id'] ?? '') === $psid && ($name = $this->text($p['name'] ?? null)) !== null) {
                    return [$name, null, false];
                }
            }
        }

        return [null, 'sin_participante', true];
    }

    /**
     * GET a la Graph API. Devuelve [respuesta|null, error|null, permanente]. El error solo lleva el
     * estado HTTP y el code/error_subcode de Meta.
     *
     * @param  array<string,string>  $query
     * @return array{0: Response|null, 1: string|null, 2: bool}
     */
    private function get(string $token, string $path, array $query, string $provider, string $operation): array
    {
        $version = (string) config('social.graph_version', 'v26.0');
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson()
                ->get("https://graph.facebook.com/{$version}/{$path}", $query);
        } catch (Throwable) {
            Log::info('social.contact.resolve: error de red', ['provider' => $provider, 'operation' => $operation]);

            return [null, 'red', false];
        }

        if ($response->successful()) {
            return [$response, null, false];
        }

        $code = is_numeric($response->json('error.code')) ? (int) $response->json('error.code') : null;
        $subcode = is_numeric($response->json('error.error_subcode')) ? (int) $response->json('error.error_subcode') : null;
        $type = $response->json('error.type');
        $error = 'http'.$response->status().($code !== null ? ' #'.$code : '').($subcode !== null ? '/'.$subcode : '');
        $permanent = $response->status() !== 429 && $response->status() < 500
            && ($code === null || in_array($code, self::PERMANENT_CODES, true) || in_array($subcode, self::PERMANENT_CODES, true));

        Log::info('social.contact.resolve: Meta no resolvió el perfil', [
            'provider' => $provider,
            'operation' => $operation,
            'status' => $response->status(),
            'code' => $code,
            'subcode' => $subcode,
            'type' => is_string($type) ? mb_substr($type, 0, 40) : null,
        ]);

        return [null, mb_substr($error, 0, 60), $permanent];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 120) : null;
    }

    private function url(mixed $value): ?string
    {
        return is_string($value) && str_starts_with($value, 'https://') ? $value : null;
    }
}
