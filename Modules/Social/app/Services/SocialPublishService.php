<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialPost;
use Modules\Social\Models\SocialPostTarget;

/**
 * Orquesta la publicación por institución. Resuelve los canales de la institución activa
 * (messenger → Facebook, instagram → Instagram), publica en cada red seleccionada de forma
 * INDEPENDIENTE (registro por red, nunca todo-o-nada) y agrega el estado del social_post.
 *
 * Idempotente por red: una red ya publicada o aún procesándose en Meta NUNCA se vuelve a
 * enviar; solo se (re)intentan las que no tienen resultado o fallaron. Así, reintentar un
 * Instagram fallido no vuelve a publicar en Facebook.
 */
final class SocialPublishService
{
    /** Red → provider del canal que la sirve. */
    public const NETWORK_PROVIDER = ['facebook' => 'messenger', 'instagram' => 'instagram'];

    /** Un contenedor de Instagram caduca a las 24 h: después no se puede publicar. */
    private const CONTAINER_TTL_HOURS = 24;

    public function __construct(private readonly MetaContentPublisher $publisher) {}

    /**
     * Redes que la institución activa PUEDE publicar (tiene canal activo), red → canal.
     *
     * @return array<string, SocialChannel>
     */
    public function availableNetworks(): array
    {
        $channels = SocialChannel::query()
            ->whereIn('provider', array_values(self::NETWORK_PROVIDER))
            ->where('is_active', true)
            ->get()
            ->keyBy('provider');

        $out = [];
        foreach (self::NETWORK_PROVIDER as $network => $provider) {
            $channel = $channels->get($provider);
            if ($channel instanceof SocialChannel) {
                $out[$network] = $channel;
            }
        }

        return $out;
    }

    /**
     * Publica el post en las redes pedidas (solo las que tengan canal). Actualiza cada target
     * con su resultado real y agrega el estado del post: published | partial | failed | processing.
     * Flujo de la publicación inmediata histórica; equivale a guardar los destinos y ejecutar.
     *
     * @param  array<int, string>  $networks
     */
    public function publish(SocialPost $post, array $networks): SocialPost
    {
        // Publicación inmediata: solo las redes con canal configurado (las demás se omiten,
        // como siempre). En una programada, un canal que desaparece después sí queda «fallido».
        $post->networks = array_values(array_intersect($networks, array_keys($this->availableNetworks())));
        $post->save();

        return $this->execute($post);
    }

    /**
     * Ejecuta la publicación en sus redes de destino (post->networks). Las redes ya publicadas o
     * en proceso se respetan; una red sin canal activo queda «fallida» con su motivo (no se
     * omite en silencio: la persona que la programó debe saber por qué no salió).
     */
    public function execute(SocialPost $post): SocialPost
    {
        $available = $this->availableNetworks();
        $existing = $post->targets()->get()->keyBy('network');

        foreach ($post->destinationNetworks() as $network) {
            /** @var SocialPostTarget|null $target */
            $target = $existing->get($network);
            if ($target !== null && in_array($target->status, ['published', 'processing'], true)) {
                continue; // nunca se publica dos veces en la misma red
            }

            $channel = $available[$network] ?? null;
            $target ??= new SocialPostTarget(['social_post_id' => $post->id, 'network' => $network]);
            $target->social_channel_id = $channel->id ?? $target->social_channel_id;
            $target->status = 'pending';
            $target->attempts = (int) $target->attempts + 1;
            $target->last_attempt_at = now();
            $target->save();

            $result = $channel === null
                ? PublishResult::fail(__('No hay una cuenta de :network activa conectada al CRM.', ['network' => SocialPostTarget::NETWORK_LABELS[$network] ?? $network]))
                : $this->guardedDispatch($network, $channel, $post);

            $this->applyResult($target, $result, $channel);
        }

        return $this->refreshStatus($post);
    }

    /**
     * Reintenta UNA red fallida (las demás no se tocan). Un contenedor de Instagram fallido no
     * se reutiliza: se prepara uno nuevo.
     */
    public function retryTarget(SocialPostTarget $target): SocialPostTarget
    {
        if ($target->status !== 'failed' || $target->remote_deleted_at !== null) {
            return $target;
        }
        $post = $target->post;
        if ($post === null) {
            return $target;
        }

        $channel = $this->availableNetworks()[$target->network] ?? null;
        $target->status = 'pending';
        $target->container_id = null;
        $target->attempts = (int) $target->attempts + 1;
        $target->last_attempt_at = now();
        $target->save();

        $result = $channel === null
            ? PublishResult::fail(__('No hay una cuenta de :network activa conectada al CRM.', ['network' => $target->networkLabel()]))
            : $this->guardedDispatch($target->network, $channel, $post);

        $this->applyResult($target, $result, $channel);
        $this->refreshStatus($post);

        return $target->refresh();
    }

    /**
     * Reanuda un target de Instagram en 'processing' usando EXCLUSIVAMENTE su container_id
     * existente (jamás crea otro contenedor). Idempotente: si el target ya no está en
     * 'processing' no hace nada, así que reanudar varias veces (botón o ejecución programada)
     * no puede duplicar la publicación. Un contenedor con más de 24 h ha caducado en Meta.
     */
    public function resumeTarget(SocialPostTarget $target): SocialPostTarget
    {
        if ($target->network !== 'instagram'
            || $target->status !== 'processing'
            || (string) $target->container_id === '') {
            return $target;
        }

        // Canal de Instagram de la institución ACTIVA (el scope ya garantiza el tenant).
        $channel = $this->availableNetworks()['instagram'] ?? null;
        if ($channel === null) {
            return $target;
        }

        $startedAt = $target->created_at;
        if ($startedAt !== null && $startedAt->lt(now()->subHours(self::CONTAINER_TTL_HOURS))) {
            $result = PublishResult::fail(__('El contenedor de Instagram caducó (24 h) sin terminar de procesarse. Reintenta la publicación en Instagram.'), (string) $target->container_id);
        } else {
            $result = $this->publisher->resumeInstagramContainer($channel, (string) $target->container_id);
        }

        $target->last_attempt_at = now();
        $this->applyResult($target, $result, $channel, keepExternalIdOnFailure: true);

        $post = $target->post;
        if ($post !== null) {
            $this->refreshStatus($post);
        }

        return $target;
    }

    /**
     * Estado agregado del post según sus targets (published | partial | failed | processing)
     * y momento efectivo de publicación (la primera red publicada).
     */
    public function refreshStatus(SocialPost $post): SocialPost
    {
        $targets = $post->targets()->get();
        $post->status = $this->rollup($targets->pluck('status')->all());
        $firstPublished = $targets->where('status', 'published')->pluck('published_at')->filter()->sort()->first();
        if ($post->published_at === null && $firstPublished !== null) {
            $post->published_at = $firstPublished;
        }
        $post->last_error = $targets->where('status', 'failed')->pluck('error_message')->filter()->first();
        $post->save();

        return $post->setRelation('targets', $targets);
    }

    /**
     * Límite de Instagram: 50 publicaciones por API cada 24 h y cuenta (Meta lo aplica en
     * media_publish). Se comprueba ANTES de llamar a Meta con lo publicado desde el CRM.
     */
    private function guardedDispatch(string $network, SocialChannel $channel, SocialPost $post): PublishResult
    {
        if ($network === 'instagram') {
            $limit = (int) config('social.instagram_daily_limit', 50);
            $used = SocialPostTarget::query()
                ->where('network', 'instagram')
                ->where('social_channel_id', $channel->id)
                ->where('status', 'published')
                ->where('published_at', '>=', now()->subDay())
                ->count();
            if ($limit > 0 && $used >= $limit) {
                return PublishResult::fail(__('Instagram admite :n publicaciones por API cada 24 horas y esta cuenta ya las alcanzó. Reintenta más tarde.', ['n' => $limit]));
            }
        }

        return $this->dispatch($network, $channel, $post);
    }

    private function applyResult(SocialPostTarget $target, PublishResult $result, ?SocialChannel $channel, bool $keepExternalIdOnFailure = false): void
    {
        $target->status = $this->targetStatus($result);
        if ($result->ok || ! $keepExternalIdOnFailure) {
            $target->external_post_id = $result->externalId !== '' ? $result->externalId : $target->external_post_id;
        }
        $target->container_id = $result->containerId ?? $target->container_id;
        $target->error_message = $result->ok ? null : $result->error;
        if ($result->ok) {
            $target->published_at ??= now();
            // Comprobación del resultado real: el enlace permanente lo da Meta para el medio publicado.
            if ($target->network === 'instagram' && $channel !== null && (string) $target->external_post_id !== '') {
                $target->permalink = $this->publisher->instagramPermalink($channel, (string) $target->external_post_id);
            }
        }
        $target->save();
    }

    private function targetStatus(PublishResult $result): string
    {
        return $result->ok ? 'published' : ($result->processing ? 'processing' : 'failed');
    }

    /**
     * Matriz de despacho (content_type, media_type, red) → método del publicador:
     *  post  + image → FB /photos                       · IG contenedor imagen
     *  post  + video → FB Video API (resumable+handle)  · IG contenedor REELS (share_to_feed)
     *  reel  + video → FB /video_reels                  · IG contenedor REELS
     *  story + image → FB photos+photo_stories          · IG contenedor STORIES (image_url)
     *  story + video → FB /video_stories                · IG contenedor STORIES (video_url)
     * El post de IMAGEN histórico conserva EXACTAMENTE su flujo; mediaUrl() resuelve
     * media_public_url con fallback a image_public_url.
     */
    private function dispatch(string $network, SocialChannel $channel, SocialPost $post): PublishResult
    {
        $mediaUrl = (string) $post->mediaUrl();
        $caption = (string) $post->caption;

        return match (true) {
            // Post de VIDEO: en FB es un video normal de Página (Video API, NO Reels);
            // en IG el feed de video se publica con contenedor REELS (share_to_feed=true).
            $post->content_type === 'post' && $post->media_type === 'video' => $network === 'facebook'
                ? $this->publisher->publishFacebookVideoPost($channel, (string) $post->media_path, $caption)
                : $this->publisher->publishInstagramReel($channel, $mediaUrl, $caption),
            $post->content_type === 'reel' => $network === 'facebook'
                ? $this->publisher->publishFacebookReel($channel, $mediaUrl, $caption)
                : $this->publisher->publishInstagramReel($channel, $mediaUrl, $caption),
            $post->content_type === 'story' && $post->media_type === 'video' => $network === 'facebook'
                ? $this->publisher->publishFacebookStoryVideo($channel, $mediaUrl)
                : $this->publisher->publishInstagramStoryVideo($channel, $mediaUrl),
            $post->content_type === 'story' => $network === 'facebook'
                ? $this->publisher->publishFacebookStoryPhoto($channel, $mediaUrl)
                : $this->publisher->publishInstagramStoryImage($channel, $mediaUrl),
            default => $network === 'facebook'
                ? $this->publisher->publishFacebookPhoto($channel, $mediaUrl, $caption)
                : $this->publisher->publishInstagramImage($channel, $mediaUrl, $caption),
        };
    }

    /**
     * Agrega el estado del post desde los estados de sus targets:
     *  - cualquier target 'processing' o 'pending' → processing (nunca 'partial' con algo en curso)
     *  - todos 'published'             → published
     *  - published + failed            → partial
     *  - todos 'failed' (o ninguno)    → failed
     *
     * @param  array<int, string>  $statuses
     */
    private function rollup(array $statuses): string
    {
        if ($statuses === []) {
            return SocialPost::FAILED;
        }

        if (array_intersect(['processing', 'pending'], $statuses) !== []) {
            return SocialPost::PROCESSING;
        }

        $ok = count(array_filter($statuses, fn (string $s): bool => $s === 'published'));
        if ($ok === count($statuses)) {
            return SocialPost::PUBLISHED;
        }

        return $ok === 0 ? SocialPost::FAILED : SocialPost::PARTIAL;
    }
}
