<?php

declare(strict_types=1);

namespace Modules\Integrations\Livewire\Integrations;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Integrations\Models\Integration;
use Modules\Integrations\Services\ConnectionTester;
use Modules\Integrations\Support\IntegrationCatalog;

/**
 * Panel de integraciones: una tarjeta por servicio (slot configurable) con su
 * estado, fecha de ultima prueba y acciones Configurar / Probar / Activar.
 *
 * Solo Administrador (Policy). El aislamiento por institucion lo da el scope
 * global de Integration; nunca se muestran secretos, solo el enmascarado.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Integration::class);
    }

    public function test(int $integrationId, ConnectionTester $tester): void
    {
        $integration = Integration::query()->findOrFail($integrationId);
        $this->authorize('test', $integration);

        $result = $tester->test($integration);

        $integration->forceFill([
            'last_tested_at' => now(),
            'last_test_ok' => $result->pending ? null : $result->ok,
            'last_test_message' => $result->message,
        ])->save();

        session()->flash('status', $integration->name.': '.$result->message);
    }

    public function toggle(int $integrationId): void
    {
        $integration = Integration::query()->findOrFail($integrationId);
        $this->authorize('update', $integration);

        $integration->status = $integration->status === 'active' ? 'inactive' : 'active';
        $integration->save();
    }

    public function render(): View
    {
        // Integraciones ya configuradas de esta institucion, indexadas por tipo.
        $configured = Integration::query()->get()->keyBy('type');

        $cards = [];
        foreach (IntegrationCatalog::all() as $type => $meta) {
            $cards[] = [
                'type' => $type,
                'label' => $meta['label'],
                'testable' => $meta['testable'],
                'integration' => $configured->get($type),
            ];
        }

        return view('integrations::livewire.integrations.index', [
            'cards' => $cards,
        ]);
    }
}
