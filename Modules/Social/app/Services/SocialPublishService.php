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
 */
final class SocialPublishService
{
    /** Red → provider del canal que la sirve. */
    public const NETWORK_PROVIDER = ['facebook' => 'messenger', 'instagram' => 'instagram'];

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
     * con su resultado real y agrega el estado del post: published | partial | failed.
     *
     * @param  array<int, string>  $networks
     */
    public function publish(SocialPost $post, array $networks): SocialPost
    {
        $available = $this->availableNetworks();
        $outcomes = [];

        foreach ($networks as $network) {
            $channel = $available[$network] ?? null;
            if ($channel === null) {
                continue; // solo se publica en redes con canal configurado
            }

            $target = new SocialPostTarget;
            $target->social_post_id = $post->id;
            $target->network = $network;
            $target->social_channel_id = $channel->id;
            $target->status = 'pending';
            $target->save();

            $result = $this->dispatch($network, $channel, $post);

            $target->status = $this->targetStatus($result);
            $target->external_post_id = $result->externalId;
            $target->container_id = $result->containerId;
            $target->error_message = $result->error;
            $target->save();

            $outcomes[] = $target->status;
        }

        $post->status = $this->rollup($outcomes);
        $post->save();

        return $post->load('targets');
    }

    /**
     * Reanuda un target de Instagram en 'processing' usando EXCLUSIVAMENTE su container_id
     * existente (jamás crea otro contenedor). Idempotente: si el target ya no está en
     * 'processing' no hace nada, así que pulsar "Continuar" varias veces no puede duplicar
     * la publicación. Esta lógica es el futuro cuerpo de un ResumeSocialTargetJob.
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

        $result = $this->publisher->resumeInstagramContainer($channel, (string) $target->container_id);

        $target->status = $this->targetStatus($result);
        if ($result->ok) {
            $target->external_post_id = $result->externalId;
        }
        $target->error_message = $result->error;
        $target->save();

        // Re-agregar el estado del post con TODOS sus targets actuales.
        $post = $target->post;
        if ($post !== null) {
            $post->status = $this->rollup($post->targets()->pluck('status')->all());
            $post->save();
        }

        return $target;
    }

    private function targetStatus(PublishResult $result): string
    {
        return $result->ok ? 'published' : ($result->processing ? 'processing' : 'failed');
    }

    /**
     * Matriz de despacho: (content_type, media_type, red) → método del publicador. Los posts
     * históricos (content_type 'post') conservan EXACTAMENTE el flujo de siempre; mediaUrl()
     * resuelve media_public_url con fallback a image_public_url.
     */
    private function dispatch(string $network, SocialChannel $channel, SocialPost $post): PublishResult
    {
        $mediaUrl = (string) $post->mediaUrl();
        $caption = (string) $post->caption;

        return match (true) {
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
     *  - cualquier target 'processing' → processing (nunca 'partial' con algo aún en curso)
     *  - todos 'published'             → published
     *  - published + failed            → partial
     *  - todos 'failed' (o ninguno)    → failed
     *
     * @param  array<int, string>  $statuses
     */
    private function rollup(array $statuses): string
    {
        if ($statuses === []) {
            return 'failed';
        }

        if (in_array('processing', $statuses, true)) {
            return 'processing';
        }

        $ok = count(array_filter($statuses, fn (string $s): bool => $s === 'published'));
        if ($ok === count($statuses)) {
            return 'published';
        }

        return $ok === 0 ? 'failed' : 'partial';
    }
}
