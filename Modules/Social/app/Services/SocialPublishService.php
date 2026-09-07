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

            $result = $network === 'facebook'
                ? $this->publisher->publishFacebookPhoto($channel, $post->image_public_url, (string) $post->caption)
                : $this->publisher->publishInstagramImage($channel, $post->image_public_url, (string) $post->caption);

            $target->status = $result->ok ? 'published' : 'failed';
            $target->external_post_id = $result->externalId;
            $target->container_id = $result->containerId;
            $target->error_message = $result->error;
            $target->save();

            $outcomes[] = $result->ok;
        }

        $post->status = $this->rollup($outcomes);
        $post->save();

        return $post->load('targets');
    }

    /**
     * @param  array<int, bool>  $outcomes
     */
    private function rollup(array $outcomes): string
    {
        if ($outcomes === []) {
            return 'failed';
        }

        $ok = count(array_filter($outcomes));
        if ($ok === count($outcomes)) {
            return 'published';
        }

        return $ok === 0 ? 'failed' : 'partial';
    }
}
