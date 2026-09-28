<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire\Knowledge;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeSyncService;
use Modules\Ai\Support\SelectedAdvisor;
use Modules\Institutions\Models\Bot;

/**
 * Panel de SOLO LECTURA del conocimiento asignado al agente seleccionado (selector de agente).
 * Conserva "Sincronizar" (flujo legado). La gestión completa (biblioteca, categorías,
 * asignaciones) vive en el Centro de Conocimiento; esta vista lo indica y enlaza allí.
 *
 * Solo Administrador (Policy). El aislamiento por institucion lo da el scope global
 * de KnowledgeSource; el bot se resuelve al unico activo de la institucion.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', KnowledgeSource::class);
    }

    public function sync(KnowledgeSyncService $service): void
    {
        $this->authorize('sync', KnowledgeSource::class);

        $bot = $this->bot();
        if ($bot === null) {
            session()->flash('status', __('No hay un bot activo para sincronizar.'));

            return;
        }

        $report = $service->sync($bot->getKey());

        session()->flash('status', __(':created creadas, :updated actualizadas, :skipped omitidas.', [
            'created' => $report['created'],
            'updated' => $report['updated'],
            'skipped' => $report['skipped'],
        ]));
    }

    public function render(): View
    {
        $bot = $this->bot();

        // Fuentes ASIGNADAS al agente seleccionado (pivote), no por bot_id.
        $sources = $bot === null
            ? collect()
            : $bot->knowledgeSources()
                ->orderByDesc('knowledge_sources.priority')
                ->orderBy('knowledge_sources.code')
                ->get();

        return view('ai::livewire.knowledge.index', [
            'sources' => $sources,
            'bot' => $bot,
        ]);
    }

    /** Agente elegido en el selector (fallback: primer bot activo). */
    private function bot(): ?Bot
    {
        return SelectedAdvisor::current();
    }
}
