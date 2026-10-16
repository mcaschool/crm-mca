<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Storage;

/** Atomic ephemeral state; a shared cache backend is required across workers. */
interface WidgetStateStore
{
    /**
     * @param  callable(array<string,mixed>):array<string,mixed>  $callback
     * @return array<string,mixed>
     */
    public function mutate(string $key, int $ttl, callable $callback): array;
}
