<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire\Knowledge;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeAssignmentService;
use Modules\Ai\Services\LineAssignmentService;
use Modules\Ai\Services\ProgramAssignmentService;
use Modules\Ai\Support\KnowledgeTaxonomy;
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

    /** @var array<int, string> Líneas con la lista de documentos desplegada (plegadas por defecto). */
    public array $openDocs = [];

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

    /** Asigna un área (con $line, solo los programas de esa línea: el área es subgrupo de la línea). */
    public function assignProgramArea(string $area, ProgramAssignmentService $programs, string $line = ''): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $bot = $this->bot();
        $n = $programs->assignArea($bot, $area, $line !== '' ? $line : null);
        session()->flash('status', __('Área asignada a :bot (:n programas).', ['bot' => $bot->assistant_name, 'n' => $n]));
    }

    public function detachProgramArea(string $area, ProgramAssignmentService $programs, string $line = ''): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $bot = $this->bot();
        $n = $programs->detachArea($bot, $area, $line !== '' ? $line : null);
        session()->flash('status', __('Área quitada de :bot (:n programas).', ['bot' => $bot->assistant_name, 'n' => $n]));
    }

    /** «Asignar línea completa»: fuentes activas + programas activos de la línea, en un paso. */
    public function assignLine(string $line, LineAssignmentService $lines): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $bot = $this->bot();
        $n = $lines->assignLine($bot, $line);
        session()->flash('status', __('Línea «:line» asignada a :bot: :s documentos y :p programas.', [
            'line' => __((string) KnowledgeTaxonomy::lineLabel($line)), 'bot' => $bot->assistant_name, 's' => $n['sources'], 'p' => $n['programs'],
        ]));
    }

    /** «Quitar línea completa»: quita del agente las fuentes activas y los programas activos de la línea. */
    public function detachLine(string $line, LineAssignmentService $lines): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $bot = $this->bot();
        $n = $lines->detachLine($bot, $line);
        session()->flash('status', __('Línea «:line» quitada de :bot: :s documentos y :p programas.', [
            'line' => __((string) KnowledgeTaxonomy::lineLabel($line)), 'bot' => $bot->assistant_name, 's' => $n['sources'], 'p' => $n['programs'],
        ]));
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

    /** Despliega/pliega los documentos de una línea (solo presentación). */
    public function toggleDocsOpen(string $line): void
    {
        $this->openDocs = in_array($line, $this->openDocs, true)
            ? array_values(array_diff($this->openDocs, [$line]))
            : [...$this->openDocs, $line];
    }

    public function render(): View
    {
        $bot = SelectedAdvisor::current();
        $programs = app(ProgramAssignmentService::class);
        $searching = trim($this->programSearch) !== '';

        // Catálogo completo (contadores de la línea) y catálogo filtrado por el buscador (filas).
        $allPrograms = $programs->catalog()->get(['id', 'line']);
        $matches = $programs->catalog($this->programSearch)->with('category:id,name_es')->get();

        return view('ai::livewire.knowledge.agents', [
            'bot' => $bot,
            'lineBlocks' => $this->lineBlocks($bot, $allPrograms, $matches, $searching),
            'programMatches' => $matches->count(),
            'programAssignedTotal' => $bot === null ? 0 : $bot->programs()->count(),
            'libraryEmpty' => ! KnowledgeSource::query()->exists() && $allPrograms->isEmpty(),
        ]);
    }

    /**
     * Un bloque por LÍNEA con las dos capas del agente: sus documentos de conocimiento
     * (knowledge_sources.category) y sus programas recomendables (programs.line, con el área
     * como subgrupo). Orden de la lista fija; al final, «Sin línea». Solo líneas con contenido.
     *
     * @param  \Illuminate\Support\Collection<int, Program>  $allPrograms
     * @param  \Illuminate\Support\Collection<int, Program>  $matches
     * @return array<int, array<string, mixed>>
     */
    private function lineBlocks(?Bot $bot, $allPrograms, $matches, bool $searching): array
    {
        $knowledge = app(KnowledgeAssignmentService::class);
        $state = $bot === null ? [] : $knowledge->assignmentsFor($bot);
        $sharedWith = $knowledge->activeAgentNamesBySource($bot);
        $assignedPrograms = $bot === null ? [] : app(ProgramAssignmentService::class)->assignedMap($bot);

        $lineOf = fn (?string $line): string => $line !== null && $line !== '' ? $line : ProgramAssignmentService::NO_LINE;

        $sourcesByLine = KnowledgeSource::query()
            ->orderByDesc('priority')->orderBy('code')->get()
            ->groupBy(fn (KnowledgeSource $s): string => $lineOf($s->category));
        $allByLine = $allPrograms->groupBy(fn (Program $p): string => $lineOf($p->line));
        $matchesByLine = $matches->groupBy(fn (Program $p): string => $lineOf($p->line));

        // Orden: la lista fija de líneas, luego valores antiguos fuera de ella y, al final, «Sin línea».
        $fixed = array_keys(KnowledgeTaxonomy::lines());
        $present = $sourcesByLine->keys()->merge($allByLine->keys())->unique();
        $legacy = $present->reject(fn (string $k): bool => in_array($k, $fixed, true) || $k === ProgramAssignmentService::NO_LINE)->sort();
        $keys = collect($fixed)->merge($legacy)->push(ProgramAssignmentService::NO_LINE);

        $blocks = [];
        foreach ($keys as $key) {
            $sources = $sourcesByLine->get($key, collect());
            $lineProgramIds = $allByLine->get($key, collect())->pluck('id')->all();
            if ($sources->isEmpty() && $lineProgramIds === []) {
                continue;
            }

            $docs = $sources->map(fn (KnowledgeSource $s): array => [
                'id' => $s->getKey(),
                'code' => $s->code,
                'name' => $s->name,
                'global_active' => $s->status === 'active',
                // assigned: null = no asignada; true = activa; false = pausada
                'assigned' => $state[$s->getKey()] ?? null,
                'shared_with' => $sharedWith[$s->getKey()] ?? [],
            ])->values()->all();

            $areas = $matchesByLine->get($key, collect())
                ->groupBy(fn (Program $p): string => $p->category_id !== null ? (string) $p->category_id : ProgramAssignmentService::NO_AREA)
                ->map(function ($items, string $area) use ($assignedPrograms, $searching, $key): array {
                    $rows = $items->map(fn (Program $p): array => [
                        'id' => $p->getKey(),
                        'code' => (string) $p->code,
                        'name' => (string) $p->name_es,
                        'active' => $p->status === 'active',
                        'assigned' => isset($assignedPrograms[(int) $p->getKey()]),
                    ])->values()->all();
                    $openKey = $key.'|'.$area;

                    return [
                        'key' => $area,
                        'open_key' => $openKey,
                        'label' => $area === ProgramAssignmentService::NO_AREA ? __('Sin área') : (string) $items->first()?->category?->name_es,
                        'rows' => $rows,
                        'assigned_count' => collect($rows)->where('assigned', true)->count(),
                        'total' => count($rows),
                        'open' => $searching || in_array($openKey, $this->openAreas, true),
                    ];
                })
                ->sortBy(fn (array $a): string => ($a['key'] === ProgramAssignmentService::NO_AREA ? '1' : '0').$a['label'])
                ->values()->all();

            $isLine = KnowledgeTaxonomy::isLine($key);
            $blocks[] = [
                'key' => $key,
                'is_line' => $isLine,
                'label' => $key === ProgramAssignmentService::NO_LINE
                    ? __('Sin línea')
                    : ($isLine ? __((string) KnowledgeTaxonomy::lineLabel($key)) : str_replace('_', ' ', $key)),
                // Clave de «Usar toda la categoría» del conocimiento (compatibilidad: sin categoría).
                'category_key' => $key === ProgramAssignmentService::NO_LINE ? KnowledgeAssignmentService::NO_CATEGORY : $key,
                'docs' => $docs,
                'docs_active' => collect($docs)->where('assigned', true)->count(),
                'docs_total' => count($docs),
                'docs_full' => $docs !== [] && collect($docs)->every(fn (array $d): bool => $d['assigned'] === true),
                'docs_open' => in_array($key, $this->openDocs, true),
                'programs_assigned' => count(array_filter($lineProgramIds, fn ($id): bool => isset($assignedPrograms[(int) $id]))),
                'programs_total' => count($lineProgramIds),
                'areas' => $areas,
            ];
        }

        return $blocks;
    }

    private function bot(): Bot
    {
        $bot = SelectedAdvisor::current();
        abort_if($bot === null, 404, 'No hay un agente activo seleccionado.');

        return $bot;
    }
}
