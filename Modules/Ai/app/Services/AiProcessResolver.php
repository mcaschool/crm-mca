<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

/**
 * Resuelve el proveedor+modelo asignado a un PROCESO de IA (conversation,
 * classification...). Prefiere la config especifica del bot; si no, la de la
 * institucion (bot_id nulo). Nada hardcoded: todo sale de ai_process_configs +
 * el almacen cifrado de integraciones.
 */
class AiProcessResolver
{
    /**
     * @return array{integration: Integration, model: string, params: array<string,mixed>}|null
     */
    public function resolve(int $botId, string $process): ?array
    {
        /** @var AiProcessConfig|null $config */
        $config = AiProcessConfig::query()
            ->where('process', $process)
            ->where('status', 'active')
            ->where(function ($query) use ($botId): void {
                $query->where('bot_id', $botId)->orWhereNull('bot_id');
            })
            ->orderByRaw('bot_id is null') // los especificos del bot primero
            ->first();

        if ($config === null) {
            return null;
        }

        /** @var Integration|null $integration */
        $integration = $config->integration()->where('status', 'active')->first();
        if ($integration === null) {
            return null;
        }

        return [
            'integration' => $integration,
            'model' => (string) $config->model,
            'params' => is_array($config->params) ? $config->params : [],
        ];
    }
}
