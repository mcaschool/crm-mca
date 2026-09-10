<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Social\Models\SocialChannel;
use Throwable;

/**
 * Publica contenido en Meta (SALIDA, Bloque 5 + multiformato): POST (foto), REEL e HISTORIA
 * (imagen o video) en Facebook Página + Instagram. Endpoints confirmados en la doc vigente
 * (versión configurable social.graph_version). El token viaja en header Bearer, nunca en la
 * URL; el medio se pasa como URL PÚBLICA (Meta lo descarga; los videos por file_url en la
 * fase de subida de rupload). Instagram comparte UN solo flujo (contenedor → status con
 * backoff → media_publish) para los tres formatos; Facebook comparte el flujo start/upload/
 * finish entre Reels e Historias de video.
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

    /** Timeout mayor para la fase de subida de video (Meta descarga el archivo vía file_url). */
    private const UPLOAD_TIMEOUT_SECONDS = 60;

    /**
     * Timeout de la TRANSFERENCIA del binario a Meta (Resumable Upload del Post de video):
     * subir hasta 250 MB desde el servidor toma el tiempo que dé el ancho de banda.
     */
    private const VIDEO_TRANSFER_TIMEOUT_SECONDS = 300;

    /** Esperas (s) entre consultas del status del contenedor IG (imagen): corto (~19s máx). */
    private const CONTAINER_POLL_DELAYS = [1, 2, 3, 5, 8];

    /**
     * Esperas (s) para contenedores de VIDEO (Reels/Historias de video): el procesamiento es
     * asíncrono, pero la espera síncrona queda ACOTADA a ~16s para no colgar la petición web
     * en hosting compartido. Si Meta necesita más, el target queda en estado RECUPERABLE
     * 'processing' (conservando el contenedor) y se reanuda con "Continuar" — la misma lógica
     * que en el futuro podrá envolver un Job en cola.
     */
    private const CONTAINER_POLL_DELAYS_VIDEO = [3, 5, 8];

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
        return $this->publishInstagramMedia($channel, ['image_url' => $imageUrl, 'caption' => $caption], isVideo: false);
    }

    /** Reel de Instagram: contenedor media_type=REELS con video_url (procesamiento asíncrono). */
    public function publishInstagramReel(SocialChannel $channel, string $videoUrl, string $caption): PublishResult
    {
        return $this->publishInstagramMedia($channel, [
            'media_type' => 'REELS',
            'video_url' => $videoUrl,
            'caption' => $caption,
            'share_to_feed' => true,
        ], isVideo: true);
    }

    /** Historia de Instagram con IMAGEN: contenedor media_type=STORIES + image_url (sin caption). */
    public function publishInstagramStoryImage(SocialChannel $channel, string $imageUrl): PublishResult
    {
        return $this->publishInstagramMedia($channel, ['media_type' => 'STORIES', 'image_url' => $imageUrl], isVideo: false);
    }

    /** Historia de Instagram con VIDEO: contenedor media_type=STORIES + video_url (asíncrono). */
    public function publishInstagramStoryVideo(SocialChannel $channel, string $videoUrl): PublishResult
    {
        return $this->publishInstagramMedia($channel, ['media_type' => 'STORIES', 'video_url' => $videoUrl], isVideo: true);
    }

    /**
     * Flujo COMPARTIDO de publicación en Instagram (Post, Reel e Historia usan exactamente el
     * mismo mecanismo: contenedor → status con backoff → media_publish con reintento 2207027).
     * Solo cambian los parámetros del contenedor y los tiempos de espera (video > imagen).
     *
     * @param  array<string, mixed>  $params
     */
    private function publishInstagramMedia(SocialChannel $channel, array $params, bool $isVideo): PublishResult
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
                ->post("{$base}/{$igUserId}/media", $params);

            if (! $create->successful()) {
                return PublishResult::fail($this->friendlyError($create, $isVideo));
            }
            $containerId = $create->json('id');
            if (! is_string($containerId) || $containerId === '') {
                return PublishResult::fail('Instagram no devolvió un contenedor de medios.');
            }

            // 2) Esperar a que el contenedor esté listo. IN_PROGRESS al primer intento es
            //    normal (Meta aún procesa el medio); se consulta con backoff acotado
            //    (más largo para video, que es asíncrono).
            $state = $this->waitForContainer($base, $containerId, $token, $isVideo);

            if ($state === 'PUBLISHED') {
                // Ya publicado (p. ej. un intento anterior llegó a completarse): éxito seguro.
                return PublishResult::ok('', $containerId);
            }
            if ($state === 'IN_PROGRESS') {
                // VIDEO: estado RECUPERABLE — el contenedor es válido, Meta solo necesita más
                // tiempo. Se conserva el containerId y se reanuda con "Continuar" (jamás se
                // marca failed ni se crea otro contenedor por esto). Imagen: reintento manual.
                return $isVideo
                    ? PublishResult::processing('Instagram continúa procesando el video.', $containerId)
                    : PublishResult::fail('Instagram continúa procesando la imagen. Intente nuevamente.', $containerId);
            }
            if ($state !== 'FINISHED') {
                // ERROR, EXPIRED o desconocido → fallo inmediato conservando el contenedor.
                return PublishResult::fail('El contenedor de Instagram no está listo (status: '.$state.').', $containerId);
            }

            return $this->publishFinishedContainer($base, $igUserId, $containerId, $token, $isVideo);
        } catch (Throwable $e) {
            Log::warning('social.publish.ig: error de red', ['error' => $e->getMessage()]);

            return PublishResult::fail('No se pudo contactar con Instagram (red).');
        }
    }

    /**
     * Reanuda un contenedor de VIDEO existente (Reel o Historia): consulta su status y, si ya
     * está listo, lo publica. JAMÁS crea un contenedor nuevo — trabaja exclusivamente con el
     * containerId recibido. Idempotente: un contenedor ya publicado (PUBLISHED) devuelve
     * éxito sin volver a llamar a media_publish.
     */
    public function resumeInstagramContainer(SocialChannel $channel, string $containerId): PublishResult
    {
        if (($fake = $this->fake('instagram')) !== null) {
            return $fake;
        }

        $token = (string) ($channel->credentials['token'] ?? '');
        $igUserId = (string) ($channel->external_id ?? '');
        if ($token === '' || $igUserId === '' || $containerId === '') {
            return PublishResult::fail('Canal de Instagram sin token/user id o sin contenedor que reanudar.');
        }

        $version = (string) config('social.graph_version', 'v26.0');
        $base = "https://graph.facebook.com/{$version}";

        try {
            $state = $this->waitForContainer($base, $containerId, $token, isVideo: true);

            if ($state === 'PUBLISHED') {
                return PublishResult::ok('', $containerId); // ya publicado: éxito idempotente
            }
            if ($state === 'IN_PROGRESS' || $state === 'UNKNOWN') {
                return PublishResult::processing('Instagram continúa procesando el video.', $containerId);
            }
            if ($state !== 'FINISHED') {
                return PublishResult::fail('El contenedor de Instagram no está listo (status: '.$state.').', $containerId);
            }

            return $this->publishFinishedContainer($base, $igUserId, $containerId, $token, isVideo: true);
        } catch (Throwable $e) {
            Log::warning('social.publish.ig.resume: error de red', ['error' => $e->getMessage()]);

            // Fallo de red → sigue siendo recuperable: no perder el contenedor.
            return PublishResult::processing('No se pudo contactar con Meta (red). Intente nuevamente.', $containerId);
        }
    }

    /**
     * media_publish de un contenedor ya FINISHED, con el reintento acotado del error
     * transitorio 2207027 (compartido por el flujo normal y la reanudación).
     */
    private function publishFinishedContainer(string $base, string $igUserId, string $containerId, string $token, bool $isVideo): PublishResult
    {
        $publish = $this->publishContainer($base, $igUserId, $containerId, $token);
        for ($retry = 0; $retry < 2 && $this->isMediaNotReady($publish); $retry++) {
            Sleep::for(3)->seconds();
            if ($this->containerStatus($base, $containerId, $token) !== 'FINISHED') {
                break;
            }
            $publish = $this->publishContainer($base, $igUserId, $containerId, $token);
        }

        if (! $publish->successful()) {
            return PublishResult::fail($this->friendlyError($publish, $isVideo), $containerId);
        }
        $postId = $publish->json('id');

        return PublishResult::ok(is_string($postId) ? $postId : '', $containerId);
    }

    /**
     * Espera a que el contenedor deje de estar IN_PROGRESS, consultando status_code con
     * backoff acotado (primera consulta inmediata; imagen: 1,2,3,5,8s · video: 3,5,8,10,15,15s).
     * Devuelve el último status visto: FINISHED | PUBLISHED | ERROR | EXPIRED | IN_PROGRESS
     * (si se agotó la espera) | UNKNOWN (respuesta sin status, se reintenta como IN_PROGRESS).
     */
    private function waitForContainer(string $base, string $containerId, string $token, bool $isVideo = false): string
    {
        $status = $this->containerStatus($base, $containerId, $token);
        $delays = $isVideo ? self::CONTAINER_POLL_DELAYS_VIDEO : self::CONTAINER_POLL_DELAYS;

        foreach ($delays as $delay) {
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

    /**
     * Reel en la Página de Facebook (flujo oficial en 3 fases con Page Access Token):
     *  1) POST /{page_id}/video_reels {upload_phase:start}  → {video_id, upload_url}
     *  2) POST al upload_url (rupload.facebook.com) con header file_url → Meta descarga el
     *     video desde la URL pública del CRM (hosted file: nada se carga en memoria PHP).
     *  3) POST /{page_id}/video_reels {upload_phase:finish, video_state:PUBLISHED, description}.
     */
    public function publishFacebookReel(SocialChannel $channel, string $videoUrl, string $caption): PublishResult
    {
        if (($fake = $this->fake('facebook')) !== null) {
            return $fake;
        }

        return $this->publishFacebookVideoFlow($channel, 'video_reels', $videoUrl, [
            'video_state' => 'PUBLISHED',
            'description' => $caption,
        ]);
    }

    /**
     * VIDEO normal en la Página (Post de video): UN SOLO request al endpoint vigente de
     * Page Videos — POST graph.facebook.com/{v}/{page_id}/videos, multipart/form-data con
     * access_token (PAGE token de siempre), description y source (el MP4 en streaming).
     * VALIDADO EN PRODUCCIÓN: el mecanismo anterior (Resumable Upload + file handle +
     * fbuploader_video_file_chunk en graph-video) devolvía 400 code 6000/subcode 1363019
     * sistemáticamente, incluso reproducido con cURL fuera de Laravel; la subida directa
     * con 'source' publica correctamente con el mismo Page token almacenado. Este flujo NO
     * usa video_upload_token ni SOCIAL_META_APP_ID.
     */
    public function publishFacebookVideoPost(SocialChannel $channel, string $mediaPath, string $caption): PublishResult
    {
        if (($fake = $this->fake('facebook')) !== null) {
            return $fake;
        }

        [$token, $pageId, $base] = $this->facebookContext($channel);
        if ($token === '' || $pageId === '') {
            return PublishResult::fail('Canal de Facebook sin token o sin page id.');
        }

        $disk = Storage::disk('public');
        if ($mediaPath === '' || ! $disk->exists($mediaPath)) {
            return PublishResult::fail('No se encontró el archivo de video en el servidor.');
        }
        $absolute = $disk->path($mediaPath);
        $size = (int) $disk->size($mediaPath);

        $stream = null;

        try {
            $stream = fopen($absolute, 'rb');
            if ($stream === false) {
                return PublishResult::fail('No se pudo leer el archivo de video del servidor.');
            }

            // multipart/form-data REAL: 'source' viaja como stream (el MP4 nunca se carga
            // entero en memoria) y el token va como parte access_token — jamás en la URL.
            $publish = Http::timeout(self::VIDEO_TRANSFER_TIMEOUT_SECONDS)->acceptJson()
                ->attach('source', $stream, basename($mediaPath), ['Content-Type' => 'video/mp4'])
                ->post("{$base}/{$pageId}/videos", [
                    'access_token' => $token,
                    'description' => $caption,
                ]);

            if (! $publish->successful()) {
                // Diagnóstico SEGURO: nunca token, Authorization, multipart, archivo ni binario.
                Log::warning('social.publish.fb.videopost.failed', [
                    'status' => $publish->status(),
                    'code' => $publish->json('error.code'),
                    'subcode' => $publish->json('error.error_subcode'),
                    'message' => $publish->json('error.message'),
                    'file_size' => $size,
                ]);

                return PublishResult::fail($this->friendlyVideoMessage($this->error($publish)));
            }
            $videoId = $publish->json('id');

            return PublishResult::ok(is_string($videoId) ? $videoId : '');
        } catch (Throwable $e) {
            Log::warning('social.publish.fb.videopost: error de red', ['error' => $e->getMessage()]);

            return PublishResult::fail('No se pudo contactar con Facebook (red).');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Historia de FOTO en la Página: 1) sube la foto SIN publicar (/photos published=false),
     * 2) la publica como historia (/photo_stories con photo_id). Guarda el post_id devuelto.
     */
    public function publishFacebookStoryPhoto(SocialChannel $channel, string $imageUrl): PublishResult
    {
        if (($fake = $this->fake('facebook')) !== null) {
            return $fake;
        }

        [$token, $pageId, $base] = $this->facebookContext($channel);
        if ($token === '' || $pageId === '') {
            return PublishResult::fail('Canal de Facebook sin token o sin page id.');
        }

        try {
            $photo = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson()
                ->post("{$base}/{$pageId}/photos", ['url' => $imageUrl, 'published' => false]);
            if (! $photo->successful()) {
                return PublishResult::fail($this->friendlyError($photo, false));
            }
            $photoId = $photo->json('id');
            if (! is_string($photoId) || $photoId === '') {
                return PublishResult::fail('Facebook no devolvió el id de la foto para la historia.');
            }

            $story = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson()
                ->post("{$base}/{$pageId}/photo_stories", ['photo_id' => $photoId]);
            if (! $story->successful()) {
                return PublishResult::fail($this->friendlyError($story, false));
            }
            $postId = $story->json('post_id') ?? $story->json('id');

            return PublishResult::ok(is_string($postId) && $postId !== '' ? $postId : $photoId);
        } catch (Throwable $e) {
            Log::warning('social.publish.fb.story: error de red', ['error' => $e->getMessage()]);

            return PublishResult::fail('No se pudo contactar con Facebook (red).');
        }
    }

    /** Historia de VIDEO en la Página (3 fases /video_stories, mismo mecanismo que el Reel). */
    public function publishFacebookStoryVideo(SocialChannel $channel, string $videoUrl): PublishResult
    {
        if (($fake = $this->fake('facebook')) !== null) {
            return $fake;
        }

        return $this->publishFacebookVideoFlow($channel, 'video_stories', $videoUrl, []);
    }

    /**
     * Flujo COMPARTIDO de video de Página (Reels e Historias de video): start → upload por
     * file_url (hosted file en rupload.facebook.com) → finish. Guarda como external id el
     * post_id devuelto por finish o, en su defecto, el video_id.
     *
     * @param  array<string, mixed>  $finishParams
     */
    private function publishFacebookVideoFlow(SocialChannel $channel, string $edge, string $videoUrl, array $finishParams): PublishResult
    {
        [$token, $pageId, $base] = $this->facebookContext($channel);
        if ($token === '' || $pageId === '') {
            return PublishResult::fail('Canal de Facebook sin token o sin page id.');
        }

        try {
            // 1) start → video_id + upload_url.
            $start = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson()
                ->post("{$base}/{$pageId}/{$edge}", ['upload_phase' => 'start']);
            if (! $start->successful()) {
                return PublishResult::fail($this->friendlyError($start, true));
            }
            $videoId = $start->json('video_id');
            if (! is_string($videoId) || $videoId === '') {
                return PublishResult::fail('Facebook no devolvió un video id.');
            }
            // Se usa EXACTAMENTE el upload_url que entrega Meta en start: nunca se
            // reconstruye a mano. Sin upload_url válido → fallo claro (con diagnóstico en
            // el log; el cuerpo de start no contiene tokens).
            $uploadUrl = $start->json('upload_url');
            if (! is_string($uploadUrl) || ! str_starts_with($uploadUrl, 'https://')) {
                Log::warning('social.publish.fb.video: start sin upload_url válido', ['edge' => $edge, 'body' => $start->json()]);

                return PublishResult::fail('Facebook no devolvió la URL de subida del video.');
            }

            // 2) upload: Meta descarga el video desde la URL pública (header file_url).
            //    Auth de rupload es "OAuth {token}" (formato documentado de esta fase).
            $upload = Http::timeout(self::UPLOAD_TIMEOUT_SECONDS)->acceptJson()
                ->withHeaders(['Authorization' => 'OAuth '.$token, 'file_url' => $videoUrl])
                ->post($uploadUrl);
            if (! $upload->successful() || $upload->json('success') !== true) {
                Log::warning('social.publish.fb.video: fallo en la fase de subida', ['edge' => $edge, 'status' => $upload->status(), 'body' => $upload->json()]);

                return PublishResult::fail('Meta no pudo descargar el video desde el servidor.');
            }

            // 3) finish.
            $finish = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson()
                ->post("{$base}/{$pageId}/{$edge}", array_merge(['upload_phase' => 'finish', 'video_id' => $videoId], $finishParams));
            if (! $finish->successful()) {
                return PublishResult::fail($this->friendlyError($finish, true));
            }
            $postId = $finish->json('post_id');

            return PublishResult::ok(is_string($postId) && $postId !== '' ? $postId : $videoId);
        } catch (Throwable $e) {
            Log::warning('social.publish.fb.video: error de red', ['edge' => $edge, 'error' => $e->getMessage()]);

            return PublishResult::fail('No se pudo contactar con Facebook (red).');
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function facebookContext(SocialChannel $channel): array
    {
        $version = (string) config('social.graph_version', 'v26.0');

        return [
            (string) ($channel->credentials['token'] ?? ''),
            (string) ($channel->external_id ?? ''),
            "https://graph.facebook.com/{$version}",
        ];
    }

    /**
     * Convierte un error de Meta en un mensaje comprensible para el usuario; el mensaje técnico
     * ORIGINAL queda siempre en el log para diagnóstico (nunca se registran tokens).
     */
    private function friendlyError(Response $response, bool $isVideo): string
    {
        $message = $this->error($response);
        Log::warning('social.publish: Meta rechazó', [
            'status' => $response->status(),
            'code' => $response->json('error.code'),
            'subcode' => $response->json('error.error_subcode'),
            'message' => $message,
        ]);

        return $isVideo ? $this->friendlyVideoMessage($message) : $message;
    }

    /** Traducción PURA (sin logs) del mensaje técnico de un error de video de Meta. */
    private function friendlyVideoMessage(string $message): string
    {
        $m = mb_strtolower($message);

        return match (true) {
            str_contains($m, 'duration') || str_contains($m, 'too long') || str_contains($m, 'too short') => 'El video excede o no alcanza la duración permitida.',
            str_contains($m, 'download') || str_contains($m, 'fetch') || str_contains($m, 'could not retrieve') => 'Meta no pudo descargar el video desde el servidor.',
            str_contains($m, 'format') || str_contains($m, 'codec') || str_contains($m, 'unsupported') || str_contains($m, 'aspect ratio') => 'El formato del video no es compatible.',
            str_contains($m, 'problem uploading your video') => 'Meta no pudo procesar la subida del video. Inténtalo de nuevo.',
            default => $message,
        };
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
