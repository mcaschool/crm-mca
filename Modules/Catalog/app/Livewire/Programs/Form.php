<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire\Programs;

use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Ai\Support\KnowledgeTaxonomy;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Catalog\Services\ProgramProvisioningService;

/**
 * Alta/edicion de un programa del catalogo. Expone AMBOS idiomas (_es/_en) para
 * poder completar el ingles que el importador dejo vacio. Gating por ProgramPolicy.
 */
#[Layout('layouts.app')]
class Form extends Component
{
    public ?int $programId = null;

    public string $code = '';

    public string $name_es = '';

    public string $name_en = '';

    public string $credential_en = '';

    public ?int $category_id = null;

    /** Línea de formación (slug de config crm.knowledge.lines) o '' = sin línea. */
    public string $line = '';

    public string $level = '';

    public string $goal = '';

    public string $profile = '';

    public string $duration_es = '';

    public string $duration_en = '';

    public string $modality_es = '';

    public string $modality_en = '';

    public string $short_description_es = '';

    public string $short_description_en = '';

    public string $url = '';

    public string $status = 'active';

    public int $display_order = 0;

    public string $tagsCsv = '';

    public function mount(?Program $program = null): void
    {
        if ($program !== null && $program->exists) {
            $this->authorize('update', $program);
            $this->fillFrom($program);

            return;
        }

        $this->authorize('create', Program::class);
    }

    private function fillFrom(Program $program): void
    {
        $this->programId = $program->getKey();
        $this->code = $program->code;
        $this->name_es = (string) $program->name_es;
        $this->name_en = (string) $program->name_en;
        $this->credential_en = (string) $program->credential_en;
        $this->category_id = $program->category_id;
        $this->line = (string) $program->line;
        $this->level = (string) $program->level;
        $this->goal = (string) $program->goal;
        $this->profile = (string) $program->profile;
        $this->duration_es = (string) $program->duration_es;
        $this->duration_en = (string) $program->duration_en;
        $this->modality_es = (string) $program->modality_es;
        $this->modality_en = (string) $program->modality_en;
        $this->short_description_es = (string) $program->short_description_es;
        $this->short_description_en = (string) $program->short_description_en;
        $this->url = $program->url;
        $this->status = $program->status;
        $this->display_order = $program->display_order;
        $this->tagsCsv = $program->tags()->pluck('tag')->implode(', ');
    }

    public function save(ProgramProvisioningService $provisioning): mixed
    {
        $editing = $this->programId !== null;
        $program = $editing ? Program::query()->findOrFail($this->programId) : new Program;

        $this->authorize($editing ? 'update' : 'create', $editing ? $program : Program::class);

        // Reglas COMUNES de alta/edición (las mismas que «+ Añadir programa» del Centro de
        // Conocimiento): normalización, formato de código y URL, duplicados por institución y
        // auditoría del alta, todas en ProgramProvisioningService.
        $this->code = trim($this->code);
        $this->name_es = $provisioning->cleanName($this->name_es);
        $this->url = trim($this->url);

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:40', 'regex:'.ProgramProvisioningService::CODE_PATTERN],
            'name_es' => ['required', 'string', 'max:200'],
            'name_en' => ['nullable', 'string', 'max:200'],
            'credential_en' => ['nullable', 'string', 'max:200'],
            // El área solo existe (y es obligatoria) en Microcredenciales.
            'category_id' => [Rule::requiredIf(Program::lineHasAreas($this->line)), 'nullable', 'integer', Rule::exists('program_categories', 'id')],
            // Líneas de programa (sin la institucional); el Catálogo admite además «sin línea».
            'line' => ['nullable', Rule::in(array_keys(KnowledgeTaxonomy::programLines()))],
            'level' => ['nullable', 'string', 'max:40'],
            'goal' => ['nullable', 'string', 'max:80'],
            'profile' => ['nullable', 'string', 'max:120'],
            'duration_es' => ['nullable', 'string', 'max:80'],
            'duration_en' => ['nullable', 'string', 'max:80'],
            'modality_es' => ['nullable', 'string', 'max:80'],
            'modality_en' => ['nullable', 'string', 'max:80'],
            'short_description_es' => ['nullable', 'string'],
            'short_description_en' => ['nullable', 'string'],
            // URL de la ficha opcional (un programa puede existir antes que su landing).
            'url' => ['nullable', 'string', 'max:500', 'regex:'.ProgramProvisioningService::URL_PATTERN],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'display_order' => ['integer'],
        ], [
            'code.regex' => ProgramProvisioningService::codeFormatMessage(),
            'url.regex' => ProgramProvisioningService::urlFormatMessage(),
        ]);

        $duplicate = $provisioning->findDuplicate($this->code, $this->name_es, $this->programId);
        if ($duplicate !== null) {
            $this->addError('code', $provisioning->duplicateMessage($duplicate));

            return null;
        }

        // Fuera de Microcredenciales el campo Área no se muestra: el formulario no toca el
        // category_id guardado (el emparejador de otras líneas aún lo usa).
        if (! Program::lineHasAreas($this->line)) {
            unset($validated['category_id']);
        }

        foreach ($validated as $key => $value) {
            $program->{$key} = $value === '' ? null : $value;
        }
        // code y url no son nullable.
        $program->code = $this->code;
        $program->url = $this->url;
        $program->status = $this->status;
        $program->display_order = $this->display_order;
        $program->save();

        if (! $editing) {
            $provisioning->recordCreated($program, ProgramProvisioningService::METHOD_CATALOG);
        }

        $this->syncTags($program);

        session()->flash('status', $editing ? __('Programa actualizado.') : __('Programa creado.'));

        return redirect()->route('catalog.programs.index');
    }

    private function syncTags(Program $program): void
    {
        $tags = collect(preg_split('/[\s,;]+/u', $this->tagsCsv) ?: [])
            ->map(fn (string $t) => mb_strtolower(trim($t)))
            ->filter()
            ->unique();

        $program->tags()->delete();
        foreach ($tags as $tag) {
            $program->tags()->create(['tag' => $tag]);
        }
    }

    public function render(): View
    {
        return view('catalog::livewire.programs.form', [
            'categories' => ProgramCategory::query()->orderBy('name_es')->get(),
            'lines' => KnowledgeTaxonomy::programLines(),
            'hasAreas' => Program::lineHasAreas($this->line),
            'editing' => $this->programId !== null,
        ]);
    }
}
