<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire\Lines;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramLine;

/**
 * Gestión de las CATEGORÍAS DE FORMACIÓN (líneas del catálogo): Microcredenciales,
 * Programas Ejecutivos, Diplomas Avanzados, etc. Listar, crear y editar (nombre es/en,
 * estado, orden). Eje separado de las áreas temáticas. Gating por ProgramPolicy
 * (Admin y Marketing gestionan el catálogo). NO toca programas (eso es la Fase 2).
 */
#[Layout('layouts.app')]
class Manage extends Component
{
    /**
     * Filas editables (vienen del formulario Livewire, por eso el valor es mixto y se
     * sanea en save()).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    public string $newNameEs = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Program::class);
        $this->loadRows();
    }

    private function loadRows(): void
    {
        $this->rows = [];
        foreach (ProgramLine::query()->orderBy('display_order')->orderBy('name_es')->get() as $line) {
            $this->rows[$line->getKey()] = [
                'name_es' => (string) $line->name_es,
                'name_en' => (string) $line->name_en,
                'status' => (string) $line->status,
                'display_order' => (int) $line->display_order,
            ];
        }
    }

    public function save(): void
    {
        $this->authorize('create', Program::class);

        foreach ($this->rows as $id => $data) {
            $line = ProgramLine::query()->find($id);
            if ($line === null) {
                continue;
            }
            $nameEs = trim((string) ($data['name_es'] ?? ''));
            if ($nameEs === '') {
                $this->addError('rows.'.$id.'.name_es', __('El nombre en español es obligatorio.'));

                return;
            }
            $line->name_es = $nameEs;
            $line->name_en = trim((string) ($data['name_en'] ?? '')) ?: null;
            $line->status = ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $line->display_order = (int) ($data['display_order'] ?? 0);
            $line->save();
        }

        session()->flash('status', __('Categorías de formación actualizadas.'));
    }

    public function addLine(): void
    {
        $this->authorize('create', Program::class);

        $validated = $this->validate([
            'newNameEs' => ['required', 'string', 'max:120'],
        ]);

        ProgramLine::query()->create([
            'name_es' => $validated['newNameEs'],
            'slug' => Str::slug($validated['newNameEs']).'-'.Str::lower(Str::random(4)),
        ]);

        $this->newNameEs = '';
        $this->loadRows();
        session()->flash('status', __('Categoría de formación creada.'));
    }

    public function render(): View
    {
        return view('catalog::livewire.lines.manage', [
            'lines' => ProgramLine::query()->orderBy('display_order')->orderBy('name_es')->get(),
        ]);
    }
}
