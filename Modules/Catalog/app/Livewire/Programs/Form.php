<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire\Programs;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Catalog\Models\ProgramLine;

/**
 * Alta/edición manual de un programa del catálogo (Fase 4). Formulario COMPLETO: incluye
 * los campos del recomendador InCompany (nivel/meta/perfil). El identificador es course_id
 * (course_idnumber): editable+obligatorio al CREAR, BLOQUEADO al editar (cambiarlo rompería
 * el vínculo con Moodle y con los leads InCompany). `code` (MC-XXX) no se usa ni se pide:
 * queda NULL en altas manuales. Dos ejes separados: categoría de formación (line_id) y área
 * temática (category_id). Gating por ProgramPolicy (solo Admin).
 */
#[Layout('layouts.app')]
class Form extends Component
{
    /** @var array<int,string> */
    private const LEVELS = ['inicial', 'intermedio', 'avanzado'];

    /** @var array<int,string> */
    private const GOALS = ['actualizar', 'ascenso', 'especializar', 'direccion', 'emprender'];

    public ?int $programId = null;

    public string $course_idnumber = '';

    public string $name_es = '';

    public string $name_en = '';

    public string $credential_en = '';

    public ?int $category_id = null;

    /** Categoría de FORMACIÓN (línea). */
    public ?int $line_id = null;

    /** Crear una categoría de formación nueva al vuelo (opcional; si viene, prevalece). */
    public string $newLineName = '';

    public string $level = '';

    public string $goal = '';

    public string $profile = '';

    public string $duration_es = '';

    public string $duration_en = '';

    public string $modality_es = '';

    public string $modality_en = '';

    public string $short_description_es = '';

    public string $short_description_en = '';

    public string $learnings_es = '';

    public string $learnings_en = '';

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
        $this->course_idnumber = (string) $program->course_idnumber;
        $this->name_es = (string) $program->name_es;
        $this->name_en = (string) $program->name_en;
        $this->credential_en = (string) $program->credential_en;
        $this->category_id = $program->category_id;
        $this->line_id = $program->line_id;
        $this->level = (string) $program->level;
        $this->goal = (string) $program->goal;
        $this->profile = (string) $program->profile;
        $this->duration_es = (string) $program->duration_es;
        $this->duration_en = (string) $program->duration_en;
        $this->modality_es = (string) $program->modality_es;
        $this->modality_en = (string) $program->modality_en;
        $this->short_description_es = (string) $program->short_description_es;
        $this->short_description_en = (string) $program->short_description_en;
        $this->learnings_es = (string) $program->learnings_es;
        $this->learnings_en = (string) $program->learnings_en;
        $this->url = (string) $program->url;
        $this->status = $program->status;
        $this->display_order = $program->display_order;
        $this->tagsCsv = $program->tags()->pluck('tag')->implode(', ');
    }

    public function save(): mixed
    {
        $editing = $this->programId !== null;
        $program = $editing ? Program::query()->findOrFail($this->programId) : new Program;
        $this->authorize($editing ? 'update' : 'create', $editing ? $program : Program::class);

        $rules = [
            'name_es' => ['required', 'string', 'max:200'],
            'name_en' => ['nullable', 'string', 'max:200'],
            'credential_en' => ['nullable', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer', Rule::exists('program_categories', 'id')],
            'line_id' => ['nullable', 'integer', Rule::exists('program_lines', 'id')],
            'newLineName' => ['nullable', 'string', 'max:120'],
            'level' => ['nullable', Rule::in(self::LEVELS)],
            'goal' => ['nullable', Rule::in(self::GOALS)],
            'profile' => ['nullable', 'string', 'max:120'],
            'duration_es' => ['nullable', 'string', 'max:80'],
            'duration_en' => ['nullable', 'string', 'max:80'],
            'modality_es' => ['nullable', 'string', 'max:80'],
            'modality_en' => ['nullable', 'string', 'max:80'],
            'short_description_es' => ['nullable', 'string'],
            'short_description_en' => ['nullable', 'string'],
            'learnings_es' => ['nullable', 'string'],
            'learnings_en' => ['nullable', 'string'],
            'url' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'display_order' => ['integer'],
        ];

        // course_id: obligatorio y único SOLO al crear; al editar está bloqueado (no cambia).
        if (! $editing) {
            $rules['course_idnumber'] = ['required', 'string', 'max:100', Rule::unique('programs', 'course_idnumber')];
        }

        $this->validate($rules);

        // Categoría de formación nueva al vuelo (prevalece sobre el selector).
        if (trim($this->newLineName) !== '') {
            $line = ProgramLine::query()->create([
                'name_es' => trim($this->newLineName),
                'slug' => Str::slug($this->newLineName).'-'.Str::lower(Str::random(4)),
            ]);
            $this->line_id = $line->getKey();
        }

        if (! $editing) {
            $program->course_idnumber = trim($this->course_idnumber);
            $program->code = null; // no se inventa MC-XXX
        }

        $program->name_es = $this->name_es;
        $program->name_en = $this->name_en ?: null;
        $program->credential_en = $this->credential_en ?: null;
        $program->category_id = $this->category_id;   // área temática (eje separado)
        $program->line_id = $this->line_id;            // categoría de formación (eje separado)
        $program->level = $this->level ?: null;
        $program->goal = $this->goal ?: null;
        $program->profile = $this->profile ?: null;
        $program->duration_es = $this->duration_es ?: null;
        $program->duration_en = $this->duration_en ?: null;
        $program->modality_es = $this->modality_es ?: null;
        $program->modality_en = $this->modality_en ?: null;
        $program->short_description_es = $this->short_description_es ?: null;
        $program->short_description_en = $this->short_description_en ?: null;
        $program->learnings_es = $this->learnings_es ?: null;
        $program->learnings_en = $this->learnings_en ?: null;
        $program->url = $this->url;   // NOT NULL: '' si viene vacío (se completa luego)
        $program->status = $this->status;
        $program->display_order = $this->display_order;
        $program->save();

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
            'lines' => ProgramLine::query()->where('status', 'active')->orderBy('display_order')->orderBy('name_es')->get(),
            'levels' => self::LEVELS,
            'goals' => self::GOALS,
            'editing' => $this->programId !== null,
        ]);
    }
}
