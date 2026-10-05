<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire\Knowledge;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Ai\Livewire\Knowledge\Concerns\ManagesCatalogPrograms;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeIngestService;
use Modules\Ai\Services\KnowledgeSyncService;
use Modules\Ai\Support\KnowledgeTaxonomy;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;

/**
 * Centro de Conocimiento — pestaña BIBLIOTECA (solo Admin, KnowledgeSourcePolicy).
 * Gestiona la biblioteca CENTRAL de fuentes (incluidas las legadas): resumen, listado con
 * filtros/búsqueda, subida múltiple de .md validados, ver contenido (drawer), activar/
 * desactivar (estado global) y borrar (fila + pivote en cascada + archivo, sin resurrección).
 *
 * NO edita asignaciones a agentes (solo las muestra): eso vive en la pestaña Agentes. NO toca
 * el scoring ni el troceo del retriever.
 */
#[Layout('layouts.app')]
class Library extends Component
{
    use ManagesCatalogPrograms;
    use WithFileUploads;

    public string $search = '';

    public string $filterCategory = '';

    public string $filterStatus = '';

    /** '' = todos · programa_academico · base_conocimiento · sin_tipo (valores antiguos). */
    public string $filterType = '';

    /** @var array<int, mixed> Archivos .md en tránsito — espacio «Base de Conocimiento». */
    public array $docs = [];

    /** Línea elegida en «Base de Conocimiento». */
    public string $kbLine = '';

    /** @var array<int, mixed> Archivos .md en tránsito — espacio «Programa Académico». */
    public array $programDocs = [];

    /** Línea elegida en «Programa Académico» (sin la institucional). */
    public string $programLine = '';

    /** Programa del catálogo elegido (id como texto, por el <select>). */
    public string $programId = '';

    /** Filtro de texto del desplegable de programas. */
    public string $programSearch = '';

    /**
     * Carga masiva por línea: el programa sale de CADA archivo («Programa: CÓDIGO» o la URL de
     * la ficha) en lugar de elegirse a mano. Con false, la subida es la de siempre.
     */
    public bool $programAuto = false;

    /** @var array<int, array{file: string, result: string, reason: string, program?: ?string}> */
    public array $uploadResults = [];

    // Drawer de contenido.
    public ?int $viewingId = null;

    public string $viewingName = '';

    public string $viewingHtml = '';

    /** @var array<int, string> */
    public array $viewingSections = [];

    // Modal de borrado.
    public ?int $deletingId = null;

    public string $deletingName = '';

    /** @var array<int, string> */
    public array $deletingAgents = [];

    public function mount(): void
    {
        $this->authorize('viewAny', KnowledgeSource::class);
    }

    /** Al cambiar la línea de «Programa Académico» se suelta el programa elegido si no es de esa línea. */
    public function updatedProgramLine(): void
    {
        if ($this->programId !== '' && ! Program::query()->whereKey((int) $this->programId)->where('line', $this->programLine)->exists()) {
            $this->programId = '';
        }
    }

    public function filterByCategory(string $category): void
    {
        $this->filterCategory = $category;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filterCategory = '';
        $this->filterStatus = '';
        $this->filterType = '';
    }

    /**
     * Espacio «Base de Conocimiento»: sube y valida .md vía el pipeline único de la biblioteca
     * (KnowledgeIngestService), etiquetados con la línea elegida y sin programa.
     */
    public function uploadDocs(KnowledgeIngestService $ingest): void
    {
        $this->authorize('sync', KnowledgeSource::class);

        // El botón nunca se deshabilita: al pulsarlo sin línea o sin archivos se explica en la tarjeta.
        $this->validate(
            [
                'kbLine' => ['required', Rule::in(array_keys(KnowledgeTaxonomy::lines()))],
                'docs' => ['required', 'array', 'min:1'],
            ],
            [
                'kbLine.required' => __('Elige la línea.'),
                'kbLine.in' => __('Línea no válida.'),
                'docs.required' => __('Elige al menos un archivo .md.'),
                'docs.min' => __('Elige al menos un archivo .md.'),
            ],
        );

        $this->runIngest($ingest, $this->docs, [
            'type' => KnowledgeTaxonomy::TYPE_KNOWLEDGE,
            'line' => $this->kbLine,
            'program_id' => null,
        ], 'docs');
        $this->docs = [];
    }

    /**
     * Espacio «Programa Académico»: exige línea (sin la institucional) y un programa ACTIVO del
     * catálogo; las fuentes quedan vinculadas a ese programa (program_id). Con la carga masiva
     * ($programAuto) no se elige programa: cada archivo resuelve el suyo en el servicio.
     */
    public function uploadProgramDocs(KnowledgeIngestService $ingest): void
    {
        $this->authorize('sync', KnowledgeSource::class);

        $rules = [
            'programLine' => ['required', Rule::in(array_keys(KnowledgeTaxonomy::programLines()))],
            'programDocs' => ['required', 'array', 'min:1'],
        ];
        if (! $this->programAuto) {
            $rules['programId'] = ['required', 'integer'];
        }

        $this->validate($rules, [
            'programLine.required' => __('Elige la línea.'),
            'programLine.in' => __('Línea no válida para un Programa Académico.'),
            'programId.required' => __('Elige el programa del catálogo.'),
            'programId.integer' => __('Elige el programa del catálogo.'),
            'programDocs.required' => __('Elige al menos un archivo .md.'),
            'programDocs.min' => __('Elige al menos un archivo .md.'),
        ]);

        if ($this->programAuto) {
            $this->runIngest($ingest, $this->programDocs, [
                'type' => KnowledgeTaxonomy::TYPE_PROGRAM,
                'line' => $this->programLine,
                'program_id' => null,
                'auto_program' => true,
            ], 'programDocs');
            $this->programDocs = [];

            return;
        }

        $program = Program::query()->where('status', 'active')->find((int) $this->programId, ['id', 'line']);
        if ($program === null) {
            $this->addError('programId', __('El programa no existe o no está activo.'));

            return;
        }
        // El servicio también lo exige; aquí se rechaza antes con el error junto al selector.
        if ($program->line !== $this->programLine) {
            $this->addError('programId', __('El programa seleccionado no pertenece a la línea académica indicada.'));

            return;
        }

        $this->runIngest($ingest, $this->programDocs, [
            'type' => KnowledgeTaxonomy::TYPE_PROGRAM,
            'line' => $this->programLine,
            'program_id' => (int) $this->programId,
        ], 'programDocs');
        $this->programDocs = [];
    }

    /** Re-sincroniza la biblioteca central (biblioteca/**\/*.md). */
    public function syncLibrary(KnowledgeSyncService $sync): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $report = $sync->syncLibrary();
        session()->flash('status', __(':created nuevas, :updated actualizadas, :skipped omitidas.', [
            'created' => $report['created'],
            'updated' => $report['updated'],
            'skipped' => $report['skipped'],
        ]));
    }

    /** Activa/desactiva el estado GLOBAL de una fuente (afecta a todos los agentes). */
    public function toggleStatus(int $id): void
    {
        $source = KnowledgeSource::query()->findOrFail($id);
        $this->authorize('update', $source);
        $source->status = $source->status === 'active' ? 'inactive' : 'active';
        $source->save();
        session()->flash('status', __('Estado actualizado.'));
    }

    /** Abre el modal de borrado mostrando qué agentes usan la fuente. */
    public function confirmDelete(int $id): void
    {
        $source = KnowledgeSource::query()->with('bots:id,assistant_name')->findOrFail($id);
        $this->authorize('delete', $source);
        $this->deletingId = $source->getKey();
        $this->deletingName = (string) $source->name;
        $this->deletingAgents = $source->bots->pluck('assistant_name')->all();
    }

    public function cancelDelete(): void
    {
        $this->reset(['deletingId', 'deletingName', 'deletingAgents']);
    }

    /** Borra la fuente (pivote en cascada) y su(s) archivo(s) .md (anti-resurrección). */
    public function delete(KnowledgeSyncService $sync): void
    {
        if ($this->deletingId === null) {
            return;
        }
        $source = KnowledgeSource::query()->findOrFail($this->deletingId);
        $this->authorize('delete', $source);

        $code = (string) $source->code;
        $name = (string) $source->name;
        $source->delete(); // el pivote bot_knowledge_source cae en cascada
        $removed = $sync->deleteFilesForCode($code);

        $this->reset(['deletingId', 'deletingName', 'deletingAgents']);
        session()->flash('status', __('Fuente «:name» eliminada; :n archivo(s) .md borrado(s).', ['name' => $name, 'n' => $removed]));
    }

    /** Drawer: renderiza el markdown (seguro) + índice de secciones. */
    public function view(int $id): void
    {
        $source = KnowledgeSource::query()->findOrFail($id);
        $this->authorize('view', $source);

        $content = (string) ($source->translate('content', 'es') ?: ($source->content_es ?? $source->content_en ?? ''));

        $this->viewingId = $source->getKey();
        $this->viewingName = (string) $source->name;
        $this->viewingHtml = Str::markdown($content, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $this->viewingSections = $this->extractSections($content);
    }

    public function closeDrawer(): void
    {
        $this->reset(['viewingId', 'viewingName', 'viewingHtml', 'viewingSections']);
    }

    public function render(): View
    {
        $query = KnowledgeSource::query()
            ->with(['bots:id,assistant_name', 'program:id,code,name_es,status,deleted_at'])
            ->withCount('bots');

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('code', 'like', $term)->orWhere('name', 'like', $term);
            });
        }
        if ($this->filterCategory !== '') {
            $this->filterCategory === 'sin_categoria'
                ? $query->whereNull('category')
                : $query->where('category', $this->filterCategory);
        }
        if ($this->filterStatus !== '') {
            $query->where('status', $this->filterStatus);
        }
        if ($this->filterType !== '') {
            $this->filterType === 'sin_tipo'
                ? $query->whereNotIn('type', array_keys(KnowledgeTaxonomy::types()))
                : $query->where('type', $this->filterType);
        }

        $sources = $query
            ->orderByRaw('category is null, category asc')
            ->orderByDesc('priority')
            ->orderBy('code')
            ->get()
            ->map(fn (KnowledgeSource $s): array => [
                'id' => $s->getKey(),
                'code' => $s->code,
                'name' => $s->name,
                'category' => $s->category,
                'type' => $s->type,
                'program' => $s->program !== null
                    ? ['name' => (string) $s->program->name_es, 'gone' => $s->program->trashed() || $s->program->status !== 'active']
                    : null,
                'priority' => $s->priority,
                'sections' => $this->countSections((string) ($s->content_es ?? $s->content_en ?? '')),
                'agents_count' => $s->bots_count,
                'agents' => $s->bots->pluck('assistant_name')->all(),
                'status' => $s->status,
                'last_synced_at' => $s->last_synced_at,
            ]);

        // Resumen (sobre TODA la biblioteca, sin filtros).
        $total = KnowledgeSource::query()->count();
        $active = KnowledgeSource::query()->where('status', 'active')->count();
        $byCategory = KnowledgeSource::query()
            ->selectRaw('category, count(*) as c')
            ->groupBy('category')
            ->pluck('c', 'category');

        // Desplegable de «Programa Académico»: solo programas ACTIVOS de la LÍNEA elegida (sin
        // línea, ninguno; SoftDeletes ya excluye los borrados) y el buscador busca dentro de esa
        // línea. El elegido se mantiene aunque el filtro de texto no lo incluya.
        $programs = Program::query()
            ->where('status', 'active')
            ->when($this->programLine === '', fn ($q) => $q->whereRaw('1 = 0'), fn ($q) => $q->where('line', $this->programLine))
            ->when(trim($this->programSearch) !== '', function ($q): void {
                $term = '%'.trim($this->programSearch).'%';
                $q->where(fn ($w) => $w->where('name_es', 'like', $term)->orWhere('code', 'like', $term));
            })
            ->orderBy('name_es')
            ->get(['id', 'code', 'name_es']);
        if ($this->programId !== '' && $this->programLine !== '' && ! $programs->contains('id', (int) $this->programId)) {
            $selected = Program::query()->where('status', 'active')->where('line', $this->programLine)->find((int) $this->programId, ['id', 'code', 'name_es']);
            if ($selected !== null) {
                $programs->prepend($selected);
            }
        }

        return view('ai::livewire.knowledge.library', [
            'sources' => $sources,
            'summary' => ['total' => $total, 'active' => $active, 'inactive' => $total - $active],
            'byCategory' => $byCategory,
            'lines' => KnowledgeTaxonomy::lines(),
            'programLines' => KnowledgeTaxonomy::programLines(),
            'types' => KnowledgeTaxonomy::types(),
            'programs' => $programs,
            'areas' => $this->showAddProgram ? ProgramCategory::query()->orderBy('name_es')->get(['id', 'name_es']) : collect(),
            'newProgramHasAreas' => Program::lineHasAreas($this->newProgramLine),
        ]);
    }

    /**
     * @param  array<int, mixed>  $files
     * @param  array{type: string, line: string, program_id: ?int, auto_program?: bool}  $classification
     */
    private function runIngest(KnowledgeIngestService $ingest, array $files, array $classification, string $errorKey): void
    {
        try {
            $this->uploadResults = $ingest->ingest($files, $classification)['results'];
        } catch (InvalidArgumentException $e) {
            $this->addError($errorKey, $e->getMessage());

            return;
        }

        session()->flash('status', __(':n archivo(s) procesado(s).', ['n' => count($this->uploadResults)]));
    }

    private function countSections(string $content): int
    {
        $n = preg_match_all('/^\#\#\s+.+$/m', $content);

        return is_int($n) ? $n : 0;
    }

    /** @return array<int, string> */
    private function extractSections(string $content): array
    {
        preg_match_all('/^\#\#\s+(.+)$/m', $content, $m);

        return array_map('trim', $m[1]);
    }
}
