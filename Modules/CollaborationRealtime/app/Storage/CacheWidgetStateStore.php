<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Storage;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

final class CacheWidgetStateStore implements WidgetStateStore
{
    public function mutate(string $key, int $ttl, callable $callback): array
    {
        $cache = Cache::store(config('collaboration_widget.cache_store'));
        $store = $cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new \RuntimeException('Widget cache requires distributed locks');
        }

        return $store->lock('widget-lock:'.$key, 10)->block(5, function () use ($cache, $key, $ttl, $callback): array {
            $state = $callback($cache->get('widget:'.$key, []));
            $cache->put('widget:'.$key, $state, $ttl);

            return $state;
        });
    }
}
