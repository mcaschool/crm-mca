<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

/**
 * Lee el catálogo (config/ai_models.php) y resuelve un ModelProfile fusionando
 * generic ← provider ← model. Es el ÚNICO punto que conoce la fuente del catálogo:
 * si mañana se mueve a una tabla `ai_models`, solo cambia esta clase; agentes,
 * Celia y adapters no se enteran. También sirve la lista de proveedores/modelos y
 * sus capacidades al panel (informativo/gating), sin exponer nada sensible.
 */
final class ModelCatalog
{
    /** @return array<string,mixed> */
    private function config(): array
    {
        return (array) config('ai_models', []);
    }

    /**
     * Perfil resuelto para (provider, model). Proveedor o modelo no catalogado →
     * perfil genérico seguro (capacidades avanzadas apagadas), sin romper.
     */
    public function profile(string $provider, string $model): ModelProfile
    {
        $cfg = $this->config();
        $generic = (array) data_get($cfg, 'generic', []);
        // OJO: el provider no tiene puntos, pero el model id sí (p. ej. "qwen3.7-plus"),
        // así que el modelo se indexa por CLAVE LITERAL, nunca por dot-notation.
        $providers = (array) data_get($cfg, 'providers', []);
        $providerCfg = is_array($providers[$provider] ?? null) ? $providers[$provider] : [];
        $models = (array) data_get($providerCfg, 'models', []);
        $modelKnown = array_key_exists($model, $models) && is_array($models[$model]);
        $modelCfg = $modelKnown ? $models[$model] : [];

        $capabilities = array_replace_recursive(
            (array) data_get($generic, 'capabilities', []),
            (array) data_get($providerCfg, 'capabilities', []),
            (array) data_get($modelCfg, 'capabilities', []),
        );

        $limits = array_replace_recursive(
            (array) data_get($generic, 'limits', []),
            (array) data_get($modelCfg, 'limits', []),
        );

        $transport = (string) (data_get($providerCfg, 'transport')
            ?? data_get($generic, 'transport', 'openai_compatible'));

        $status = $modelKnown ? (string) data_get($modelCfg, 'status', 'supported') : 'unknown';

        return new ModelProfile(
            provider: $provider,
            model: $model,
            transport: $transport,
            label: (string) data_get($modelCfg, 'label', $model),
            status: $status,
            known: $modelKnown,
            capabilities: $capabilities,
            limits: $limits,
            errorMap: (array) data_get($providerCfg, 'error_map', []),
            usageMap: (array) data_get($providerCfg, 'usage_map', []),
        );
    }

    /**
     * Proveedores catalogados => etiqueta amigable (para el panel).
     *
     * @return array<string,string>
     */
    public function providers(): array
    {
        $out = [];
        foreach ((array) data_get($this->config(), 'providers', []) as $key => $cfg) {
            $out[(string) $key] = (string) (is_array($cfg) ? ($cfg['label'] ?? $key) : $key);
        }

        return $out;
    }

    /**
     * Modelos catalogados de un proveedor (para datalist/sugerencias del panel).
     *
     * @return list<array{id:string,label:string,status:string}>
     */
    public function modelsFor(string $provider): array
    {
        $out = [];
        foreach ((array) data_get($this->config(), "providers.{$provider}.models", []) as $id => $cfg) {
            $out[] = [
                'id' => (string) $id,
                'label' => (string) (is_array($cfg) ? ($cfg['label'] ?? $id) : $id),
                'status' => (string) (is_array($cfg) ? ($cfg['status'] ?? 'supported') : 'supported'),
            ];
        }

        return $out;
    }
}
