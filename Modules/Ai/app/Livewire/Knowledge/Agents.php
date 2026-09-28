<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire\Knowledge;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeAssignmentService;
use Modules\Ai\Services\ProgramAssignmentService;
use Modules\Ai\Support\SelectedAdvisor;
use Modules\Catalog\Models\Program;
use Modules\Institutions\Models\Bot;

/**
 * Centro de Conocimiento — pestaña AGENTES (solo Admin). Para el agente seleccionado muestra
 * la biblioteca agrupada por categoría (incluida "Sin categoría") y permite: usar toda una
 * categoría, activar/pausar una fuente concreta y quitarla del agente. Indica qué fuentes
 * están activas para el agente y con qué otros agentes se comparten.
 *
 * Sección «Programas que puede recomendar» (Bloque 4c): qué programas del catálogo usa el
 * emparejador de ese agente, por programa, por área o por resultados de búsqueda.
 *
 * Toda la lógica de asignación vive en KnowledgeAssignmentService / ProgramAssignmentService
 * (sin duplicarla aquí).
 */
#[Layout('layouts.app')]
class Agents extends Component
{
    /** Búsqueda en «Programas que puede recomendar» (nombre o código). */
    public string $programSearch = '';

    /** @var array<int, string> Áreas desplegadas (sin búsqueda); con búsqueda se ven todas. */
    public array $openAreas = [];

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

    // --- Programas que puede recomendar (Bloque 4c; lógica en ProgramAssignmentService) ---

    /** Interruptor de un programa para el asesor seleccionado. */
    public function toggleProgram(int $id, ProgramAssignmentService $programs): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $programs->toggle($this->bot(), Program::query()->findOrFail($id));
    }

    public function assignProgramArea(string $area, ProgramAssignmentService $programs): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $bot = $this->bot();
        $n = $programs->assignArea($bot, $area);
        session()->flash('status', __('Área asignada a :bot (:n programas).', ['bot' => $bot->assistant_name, 'n' => $n]));
    }

    public function detachProgramArea(string $area, ProgramAssignmentService $programs): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $bot = $this->bot();
        $n = $programs->detachArea($bot, $area);
        session()->flash('status', __('Área quitada de :bot (:n programas).', ['bot' => $bot->assistant_name, 'n' => $n]));
    }

    /** Asigna todos los programas que coinciden con la búsqueda actual. */
    public function assignProgramResults(ProgramAssignmentService $programs): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $bot = $this->bot();
        $n = $programs->assignMatching($bot, $this->programSearch);
        session()->flash('status', __(':n programas asignados a :bot.', ['bot' => $bot->assistant_name, 'n' => $n]));
    }

    /** Quita todos los programas que coinciden con la búsqueda actual. */
    public function detachProgramResults(ProgramAssignmentService $programs): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $bot = $this->bot();
        $n = $programs->detachMatching($bot, $this->programSearch);
        session()->flash('status', __(':n programas quitados de :bot.', ['bot' => $bot->assistant_name, 'n' => $n]));
    }

    /** Despliega/pliega un área (solo presentación). */
    public function toggleProgramAreaOpen(string $area): void
    {
        $this->openAreas = in_array($area, $this->openAreas, true)
            ? array_values(array_diff($this->openAreas, [$area]))
            : [...$this->openAreas, $area];
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
            ...$this->programData($bot),
        ]);
    }

    /**
     * Catálogo agrupado por área con el estado de asignación del asesor seleccionado.
     *
     * @return array{programAreas: array<int, array<string, mixed>>, programMatches: int, programAssignedTotal: int}
     */
    private function programData(?Bot $bot): array
    {
        $service = app(ProgramAssignmentService::class);
        $assigned = $bot === null ? [] : $service->assignedMap($bot);
        $searching = trim($this->programSearch) !== '';

        $programs = $service->catalog($this->programSearch)->with('category:id,name_es')->get();

        $areas = $programs
            ->groupBy(fn (Program $p): string => $p->category_id !== null ? (string) $p->category_id : ProgramAssignmentService::NO_AREA)
            ->map(function ($items, string $key) use ($assigned, $searching): array {
                $rows = $items->map(fn (Program $p): array => [
                    'id' => $p->getKey(),
                    'code' => (string) $p->code,
                    'name' => (string) $p->name_es,
                    'active' => $p->status === 'active',
                    'assigned' => isset($assigned[(int) $p->getKey()]),
                ])->values()->all();
                $assignedCount = collect($rows)->where('assigned', true)->count();

                return [
                    'key' => $key,
                    'label' => $key === ProgramAssignmentService::NO_AREA ? __('Sin área') : (string) $items->first()?->category?->name_es,
                    'rows' => $rows,
                    'assigned_count' => $assignedCount,
                    'total' => count($rows),
                    'open' => $searching || in_array($key, $this->openAreas, true),
                ];
            })
            ->sortBy(fn (array $a): string => ($a['key'] === ProgramAssignmentService::NO_AREA ? '1' : '0').$a['label'])
            ->values()
            ->all();

        return [
            'programAreas' => $areas,
            'programMatches' => $programs->count(),
            'programAssignedTotal' => $bot === null ? 0 : $bot->programs()->count(),
        ];
    }

    private function bot(): Bot
    {
        $bot = SelectedAdvisor::current();
        abort_if($bot === null, 404, 'No hay un agente activo seleccionado.');

        return $bot;
    }
}
