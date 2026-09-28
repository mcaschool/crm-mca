<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire\Knowledge;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeIngestService;
use Modules\Ai\Services\KnowledgeSyncService;

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
    use WithFileUploads;

    public string $search = '';

    public string $filterCategory = '';

    public string $filterStatus = '';

    /** @var array<int, mixed> Archivos .md en tránsito. */
    public array $docs = [];

    /** @var array<int, array{file: string, result: string, reason: string}> */
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

    public function filterByCategory(string $category): void
    {
        $this->filterCategory = $category;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filterCategory = '';
        $this->filterStatus = '';
    }

    /** Sube y valida .md vía el pipeline único de la biblioteca (KnowledgeIngestService). */
    public function uploadDocs(KnowledgeIngestService $ingest): void
    {
        $this->authorize('sync', KnowledgeSource::class);

        if ($this->docs === []) {
            return;
        }

        $this->uploadResults = $ingest->ingest($this->docs)['results'];
        $this->docs = [];
        session()->flash('status', __(':n archivo(s) procesado(s).', ['n' => count($this->uploadResults)]));
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
        $query = KnowledgeSource::query()->with('bots:id,assistant_name')->withCount('bots');

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

        return view('ai::livewire.knowledge.library', [
            'sources' => $sources,
            'summary' => ['total' => $total, 'active' => $active, 'inactive' => $total - $active],
            'byCategory' => $byCategory,
        ]);
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
