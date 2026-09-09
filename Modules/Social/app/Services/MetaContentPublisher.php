<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Social\Models\SocialChannel;
use Throwable;

/**
 * Publica una imagen con descripción en Meta (SALIDA de contenido, Bloque 5). Endpoints
 * confirmados en la doc vigente (versión configurable social.graph_version). El token viaja en
 * header Bearer, nunca en la URL; la imagen se pasa como URL PÚBLICA (Meta la descarga).
 *
 *  - Facebook (foto en Página): POST https://graph.facebook.com/{v}/{page_id}/photos
 *      body {url, message, published:true} → devuelve {id, post_id}.
 *  - Instagram (2 pasos, Graph API de Facebook): host graph.facebook.com con el Page Access
 *      Token EAA del canal (el mismo que usa Facebook y la mensajería; graph.instagram.com
 *      espera un token IGAA → 190 "Cannot parse access token"). Nodo = IG User ID (external_id
 *      del canal de Instagram).
 *      1) POST /{ig_user_id}/media  {image_url, caption}  → {id: creation_id (contenedor)}
 *      2) GET  /{creation_id}?fields=status_code — IN_PROGRESS es NORMAL unos segundos tras
 *         crear el contenedor: se espera con backoff corto y acotado (1s,2s,3s,5s,8s) hasta
 *         FINISHED. ERROR/EXPIRED fallan de inmediato; PUBLISHED se trata como ya publicado.
 *      3) POST /{ig_user_id}/media_publish  {creation_id} → {id: post_id}. Si Meta responde
 *         2207027 ("media not available", transitorio), se reintenta hasta 2 veces tras
 *         re-confirmar FINISHED.
 */
final class MetaContentPublisher
{
    private const TIMEOUT_SECONDS = 20;

    /** Esperas (s) entre consultas del status del contenedor IG: corto y acotado (~19s máx). */
    private const CONTAINER_POLL_DELAYS = [1, 2, 3, 5, 8];

    public function publishFacebookPhoto(SocialChannel $channel, string $imageUrl, string $caption): PublishResult
    {
        if (($fake = $this->fake('facebook')) !== null) {
            return $fake;
        }

        $token = (string) ($channel->credentials['token'] ?? '');
        $pageId = (string) ($channel->external_id ?? '');
        if ($token === '' || $pageId === '') {
            return PublishResult::fail('Canal de Facebook sin token o sin page id.');
        }

        $version = (string) config('social.graph_version', 'v26.0');

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->post("https://graph.facebook.com/{$version}/{$pageId}/photos", [
                    'url' => $imageUrl,
                    'message' => $caption,
                    'published' => true,
                ]);
        } catch (Throwable $e) {
            Log::warning('social.publish.fb: error de red', ['error' => $e->getMessage()]);

            return PublishResult::fail('No se pudo contactar con Facebook (red).');
        }

        if ($response->successful()) {
            $postId = $response->json('post_id') ?? $response->json('id');

            return PublishResult::ok(is_string($postId) ? $postId : '');
        }

        return PublishResult::fail($this->error($response));
    }

    public function publishInstagramImage(SocialChannel $channel, string $imageUrl, string $caption): PublishResult
    {
        if (($fake = $this->fake('instagram')) !== null) {
            return $fake;
        }

        $token = (string) ($channel->credentials['token'] ?? '');
        $igUserId = (string) ($channel->external_id ?? '');
        if ($token === '' || $igUserId === '') {
            return PublishResult::fail('Canal de Instagram sin token o sin user id.');
        }

        $version = (string) config('social.graph_version', 'v26.0');
        // Token EAA (Page Access Token) → Graph API de Facebook, NO graph.instagram.com
        // (ese espera IGAA → 190). El nodo sigue siendo el IG User ID (external_id del canal).
        $base = "https://graph.facebook.com/{$version}";

        try {
            // 1) Crear contenedor.
            $create = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson()
                ->post("{$base}/{$igUserId}/media", ['image_url' => $imageUrl, 'caption' => $caption]);

            if (! $create->successful()) {
                return PublishResult::fail($this->error($create));
            }
            $containerId = $create->json('id');
            if (! is_string($containerId) || $containerId === '') {
                return PublishResult::fail('Instagram no devolvió un contenedor de medios.');
            }

            // 2) Esperar a que el contenedor esté listo. IN_PROGRESS al primer intento es
            //    normal (Meta aún procesa la imagen); se consulta con backoff acotado.
            $state = $this->waitForContainer($base, $containerId, $token);

            if ($state === 'PUBLISHED') {
                // Ya publicado (p. ej. un intento anterior llegó a completarse): éxito seguro.
                return PublishResult::ok('', $containerId);
            }
            if ($state === 'IN_PROGRESS') {
                return PublishResult::fail('Instagram continúa procesando la imagen. Intente nuevamente.', $containerId);
            }
            if ($state !== 'FINISHED') {
                // ERROR, EXPIRED o desconocido → fallo inmediato conservando el contenedor.
                return PublishResult::fail('El contenedor de Instagram no está listo (status: '.$state.').', $containerId);
            }

            // 3) Publicar el contenedor. 2207027 ("media not available") puede ser transitorio
            //    justo tras FINISHED: hasta 2 reintentos, re-confirmando FINISHED antes de cada uno.
            $publish = $this->publishContainer($base, $igUserId, $containerId, $token);
            for ($retry = 0; $retry < 2 && $this->isMediaNotReady($publish); $retry++) {
                Sleep::for(3)->seconds();
                if ($this->containerStatus($base, $containerId, $token) !== 'FINISHED') {
                    break;
                }
                $publish = $this->publishContainer($base, $igUserId, $containerId, $token);
            }

            if (! $publish->successful()) {
                return PublishResult::fail($this->error($publish), $containerId);
            }
            $postId = $publish->json('id');

            return PublishResult::ok(is_string($postId) ? $postId : '', $containerId);
        } catch (Throwable $e) {
            Log::warning('social.publish.ig: error de red', ['error' => $e->getMessage()]);

            return PublishResult::fail('No se pudo contactar con Instagram (red).');
        }
    }

    /**
     * Espera a que el contenedor deje de estar IN_PROGRESS, consultando status_code con
     * backoff corto y acotado (primera consulta inmediata; luego 1s,2s,3s,5s,8s). Devuelve
     * el último status visto: FINISHED | PUBLISHED | ERROR | EXPIRED | IN_PROGRESS (si se
     * agotó la espera) | UNKNOWN (respuesta sin status, se reintenta como IN_PROGRESS).
     */
    private function waitForContainer(string $base, string $containerId, string $token): string
    {
        $status = $this->containerStatus($base, $containerId, $token);

        foreach (self::CONTAINER_POLL_DELAYS as $delay) {
            if (! in_array($status, ['IN_PROGRESS', 'UNKNOWN'], true)) {
                return $status;
            }
            Sleep::for($delay)->seconds();
            $status = $this->containerStatus($base, $containerId, $token);
        }

        return $status;
    }

    /** Una consulta del status_code del contenedor ('UNKNOWN' si la respuesta no lo trae). */
    private function containerStatus(string $base, string $containerId, string $token): string
    {
        $response = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson()
            ->get("{$base}/{$containerId}", ['fields' => 'status_code']);
        $code = $response->json('status_code');

        return is_string($code) && $code !== '' ? $code : 'UNKNOWN';
    }

    private function publishContainer(string $base, string $igUserId, string $containerId, string $token): Response
    {
        return Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson()
            ->post("{$base}/{$igUserId}/media_publish", ['creation_id' => $containerId]);
    }

    /** Error Meta 2207027: "Media ID is not available" — transitorio justo tras FINISHED. */
    private function isMediaNotReady(Response $response): bool
    {
        if ($response->successful()) {
            return false;
        }

        return (int) ($response->json('error.error_subcode') ?? 0) === 2207027
            || (int) ($response->json('error.code') ?? 0) === 2207027;
    }

    private function error(Response $response): string
    {
        $message = $response->json('error.message');

        return is_string($message) && $message !== '' ? $message : 'Error de Meta ('.$response->status().').';
    }

    /**
     * Atajo SOLO-LOCAL (social.fake_publish = ok|partial|fail) para verificación visual sin
     * tokens reales. 'partial' = Facebook ok, Instagram falla (el caso que más importa mostrar).
     */
    private function fake(string $network): ?PublishResult
    {
        $mode = config('social.fake_publish');
        if (! is_string($mode) || $mode === '' || ! app()->environment('local')) {
            return null;
        }

        if ($mode === 'fail' || ($mode === 'partial' && $network === 'instagram')) {
            return $network === 'instagram'
                ? PublishResult::fail('La imagen no cumple la relación de aspecto de Instagram (debe estar entre 4:5 y 1.91:1).', 'CONT_FAKE_'.Str::upper(Str::random(8)))
                : PublishResult::fail('Simulado (local): error de publicación en Facebook.');
        }

        return $network === 'instagram'
            ? PublishResult::ok('IG_FAKE_'.Str::upper(Str::random(10)), 'CONT_FAKE_'.Str::upper(Str::random(8)))
            : PublishResult::ok('FB_FAKE_'.Str::upper(Str::random(10)));
    }
}
