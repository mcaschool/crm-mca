<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
 *      2) GET  /{creation_id}?fields=status_code          → debe ser FINISHED antes de publicar
 *      3) POST /{ig_user_id}/media_publish  {creation_id} → {id: post_id}
 */
final class MetaContentPublisher
{
    private const TIMEOUT_SECONDS = 20;

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

            // 2) Verificar que el contenedor está listo (para imágenes suele ser inmediato).
            $status = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson()
                ->get("{$base}/{$containerId}", ['fields' => 'status_code']);
            $code = $status->json('status_code');
            if ($code !== 'FINISHED') {
                return PublishResult::fail('El contenedor de Instagram no está listo (status: '.(is_string($code) ? $code : 'desconocido').').', $containerId);
            }

            // 3) Publicar el contenedor.
            $publish = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson()
                ->post("{$base}/{$igUserId}/media_publish", ['creation_id' => $containerId]);

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
