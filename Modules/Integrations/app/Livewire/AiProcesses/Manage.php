<?php

declare(strict_types=1);

namespace Modules\Integrations\Livewire\AiProcesses;

use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Ai\Services\ModelCatalog;
use Modules\Ai\Services\ModelProfile;
use Modules\Institutions\Models\Bot;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

/**
 * Asignacion proveedor+modelo por PROCESO (conversation/classification/summary/
 * email_draft), acotada a un bot. Es SOLO la configuracion: la llamada real al
 * modelo es del Bloque 6.
 *
 * Solo Administrador (misma puerta que integraciones). Aislamiento por el scope
 * global de los modelos implicados (Bot, Integration, AiProcessConfig).
 */
#[Layout('layouts.app')]
class Manage extends Component
{
    public ?int $botId = null;

    /** @var array<string, array{integration_id: ?int, model: string, thinking?: bool, temperature?: string, max_tokens?: string, timeout?: string}> */
    public array $rows = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Integration::class);

        $firstBot = Bot::query()->orderBy('name')->first();
        $this->botId = $firstBot?->getKey();
        $this->loadRows();
    }

    public function updatedBotId(): void
    {
        $this->loadRows();
    }

    private function loadRows(): void
    {
        $this->rows = [];

        $existing = $this->botId === null
            ? collect()
            : AiProcessConfig::query()->where('bot_id', $this->botId)->get()->keyBy('process');

        foreach ($this->processes() as $process) {
            $config = $existing->get($process);
            $params = $config instanceof AiProcessConfig && is_array($config->params) ? $config->params : [];
            $this->rows[$process] = [
                'integration_id' => $config instanceof AiProcessConfig ? $config->integration_id : null,
                'model' => $config instanceof AiProcessConfig ? (string) $config->model : '',
                'thinking' => (bool) ($params['thinking'] ?? false),
                'temperature' => isset($params['temperature']) ? (string) $params['temperature'] : '',
                'max_tokens' => isset($params['max_tokens']) ? (string) $params['max_tokens'] : '',
                'timeout' => isset($params['timeout']) ? (string) $params['timeout'] : '',
            ];
        }
    }

    /**
     * Capacidades RESUELTAS de la fila (proveedor de la integración + modelo escrito),
     * para que la vista muestre solo lo compatible. null si la fila está incompleta.
     */
    public function profileFor(string $process): ?ModelProfile
    {
        $row = $this->rows[$process] ?? null;
        if ($row === null || empty($row['integration_id']) || trim((string) $row['model']) === '') {
            return null;
        }

        $integration = Integration::query()->find($row['integration_id']);
        if ($integration === null) {
            return null;
        }

        return app(ModelCatalog::class)->profile((string) ($integration->provider ?? ''), trim((string) $row['model']));
    }

    public function save(): void
    {
        $this->authorize('create', Integration::class);

        abort_if($this->botId === null, 422);

        // El bot debe ser de esta institucion (scope global lo garantiza).
        $bot = Bot::query()->findOrFail($this->botId);

        $aiIntegrations = Integration::query()->where('type', 'ai_provider')->get()->keyBy('id');
        $aiIntegrationIds = $aiIntegrations->keys()->all();
        $catalog = app(ModelCatalog::class);

        foreach ($this->processes() as $process) {
            $row = $this->rows[$process] ?? ['integration_id' => null, 'model' => ''];

            // Fila vacia: no se crea configuracion para ese proceso.
            if (empty($row['integration_id']) || trim((string) $row['model']) === '') {
                continue;
            }

            $this->validate([
                "rows.{$process}.integration_id" => ['required', Rule::in($aiIntegrationIds)],
                "rows.{$process}.model" => ['required', 'string', 'max:100'],
            ]);

            $model = trim((string) $row['model']);
            $integration = $aiIntegrations->get((int) $row['integration_id']);
            $profile = $catalog->profile((string) ($integration->provider ?? ''), $model);

            // Overrides GATEADOS por capacidad: nunca se guarda algo que el modelo no soporta.
            $params = [];
            if ($profile->supportsThinking() && ! empty($row['thinking'])) {
                $params['thinking'] = true;
            }
            if (is_numeric($row['temperature'] ?? null)) {
                $params['temperature'] = (float) $row['temperature'];
            }
            if (is_numeric($row['max_tokens'] ?? null)) {
                $mt = (int) $row['max_tokens'];
                if ($profile->maxOutput() !== null) {
                    $mt = min($mt, $profile->maxOutput());
                }
                $params['max_tokens'] = $mt;
            }
            if (is_numeric($row['timeout'] ?? null)) {
                $params['timeout'] = (int) $row['timeout'];
            }

            AiProcessConfig::query()->updateOrCreate(
                ['bot_id' => $bot->getKey(), 'process' => $process],
                ['integration_id' => (int) $row['integration_id'], 'model' => $model, 'params' => $params === [] ? null : $params, 'status' => 'active'],
            );
        }

        session()->flash('status', __('Configuracion de IA guardada.'));
    }

    /** @return array<int, string> */
    private function processes(): array
    {
        return (array) config('crm.ai_processes', []);
    }

    public function render(): View
    {
        $catalog = app(ModelCatalog::class);
        $aiIntegrations = Integration::query()->where('type', 'ai_provider')->get();

        // Modelos conocidos por proveedor (para datalist de sugerencias en el panel).
        $modelsByProvider = [];
        foreach ($aiIntegrations->pluck('provider')->filter()->unique() as $prov) {
            $modelsByProvider[(string) $prov] = $catalog->modelsFor((string) $prov);
        }

        return view('integrations::livewire.ai-processes.manage', [
            'bots' => Bot::query()->orderBy('name')->get(),
            'processes' => $this->processes(),
            'aiIntegrations' => $aiIntegrations,
            'modelsByProvider' => $modelsByProvider,
        ]);
    }
}
