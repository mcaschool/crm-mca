<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire\Knowledge;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeAssignmentService;
use Modules\Ai\Support\SelectedAdvisor;
use Modules\Institutions\Models\Bot;

/**
 * Centro de Conocimiento — pestaña AGENTES (solo Admin). Para el agente seleccionado muestra
 * la biblioteca agrupada por categoría (incluida "Sin categoría") y permite: usar toda una
 * categoría, activar/pausar una fuente concreta y quitarla del agente. Indica qué fuentes
 * están activas para el agente y con qué otros agentes se comparten.
 *
 * Toda la lógica de asignación vive en KnowledgeAssignmentService (sin duplicarla aquí).
 */
#[Layout('layouts.app')]
class Agents extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', KnowledgeSource::class);
    }

    /** "Usar toda la categoría": si ya está completa, la quita; si no, la asigna entera. */
    public function toggleCategory(string $category, KnowledgeAssignmentService $assign): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $bot = $this->bot();

        if ($assign->isCategoryFullyActive($bot, $category)) {
            $n = $assign->detachCategory($bot, $category);
            session()->flash('status', __('Categoría quitada de :bot (:n fuentes).', ['bot' => $bot->assistant_name, 'n' => $n]));
        } else {
            $n = $assign->assignCategory($bot, $category);
            session()->flash('status', __('Categoría asignada a :bot (:n fuentes).', ['bot' => $bot->assistant_name, 'n' => $n]));
        }
    }

    /** Interruptor de una fuente: si no está asignada, la asigna; si lo está, activa/pausa. */
    public function toggleSource(int $id, KnowledgeAssignmentService $assign): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $assign->toggleAssignment($this->bot(), KnowledgeSource::query()->findOrFail($id));
    }

    /** Quita la fuente SOLO de este agente (no la borra ni afecta a otros agentes). */
    public function detachSource(int $id, KnowledgeAssignmentService $assign): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $bot = $this->bot();
        $source = KnowledgeSource::query()->findOrFail($id);
        $assign->detach($bot, $source);
        session()->flash('status', __('«:name» quitada de :bot.', ['name' => $source->name, 'bot' => $bot->assistant_name]));
    }

    public function render(): View
    {
        $bot = SelectedAdvisor::current();
        $assign = app(KnowledgeAssignmentService::class);

        // Estado de asignación del agente (id => activa/pausada) y con quién se comparte.
        $state = $bot === null ? [] : $assign->assignmentsFor($bot);
        $sharedWith = $assign->activeAgentNamesBySource($bot);

        $groups = KnowledgeSource::query()
            ->orderByRaw('category is null, category asc')
            ->orderByDesc('priority')
            ->orderBy('code')
            ->get()
            ->groupBy(fn (KnowledgeSource $s): string => $s->category ?? KnowledgeAssignmentService::NO_CATEGORY)
            ->map(function ($sources, string $category) use ($state, $sharedWith): array {
                $rows = $sources->map(fn (KnowledgeSource $s): array => [
                    'id' => $s->getKey(),
                    'code' => $s->code,
                    'name' => $s->name,
                    'global_active' => $s->status === 'active',
                    // assigned: null = no asignada; true = activa; false = pausada
                    'assigned' => $state[$s->getKey()] ?? null,
                    'shared_with' => $sharedWith[$s->getKey()] ?? [],
                ])->values()->all();

                return [
                    'key' => $category,
                    'label' => $category === KnowledgeAssignmentService::NO_CATEGORY ? __('Sin categoría') : str_replace('_', ' ', $category),
                    'rows' => $rows,
                    'active_count' => collect($rows)->where('assigned', true)->count(),
                    'full' => $rows !== [] && collect($rows)->every(fn (array $r): bool => $r['assigned'] === true),
                ];
            })
            ->values();

        return view('ai::livewire.knowledge.agents', [
            'bot' => $bot,
            'groups' => $groups,
        ]);
    }

    private function bot(): Bot
    {
        $bot = SelectedAdvisor::current();
        abort_if($bot === null, 404, 'No hay un agente activo seleccionado.');

        return $bot;
    }
}
