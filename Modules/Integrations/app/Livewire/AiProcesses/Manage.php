<?php

declare(strict_types=1);

namespace Modules\Integrations\Livewire\AiProcesses;

use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
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

    /** @var array<string, array{integration_id: ?int, model: string}> */
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
            $this->rows[$process] = [
                'integration_id' => $config instanceof AiProcessConfig ? $config->integration_id : null,
                'model' => $config instanceof AiProcessConfig ? (string) $config->model : '',
            ];
        }
    }

    public function save(): void
    {
        $this->authorize('create', Integration::class);

        abort_if($this->botId === null, 422);

        // El bot debe ser de esta institucion (scope global lo garantiza).
        $bot = Bot::query()->findOrFail($this->botId);

        $aiIntegrationIds = Integration::query()->where('type', 'ai_provider')->pluck('id')->all();

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

            AiProcessConfig::query()->updateOrCreate(
                ['bot_id' => $bot->getKey(), 'process' => $process],
                ['integration_id' => (int) $row['integration_id'], 'model' => trim((string) $row['model']), 'status' => 'active'],
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
        return view('integrations::livewire.ai-processes.manage', [
            'bots' => Bot::query()->orderBy('name')->get(),
            'processes' => $this->processes(),
            'aiIntegrations' => Integration::query()->where('type', 'ai_provider')->get(),
        ]);
    }
}
