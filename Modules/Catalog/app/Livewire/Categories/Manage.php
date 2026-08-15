<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire\Categories;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;

/**
 * Gestion de categorias del catalogo: editar nombre en ambos idiomas (_es/_en)
 * y crear nuevas. Gating por ProgramPolicy (Admin y Marketing).
 */
#[Layout('layouts.app')]
class Manage extends Component
{
    /** @var array<int, array{name_es: string, name_en: string}> */
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
        foreach (ProgramCategory::query()->orderBy('name_es')->get() as $category) {
            $this->rows[$category->getKey()] = [
                'name_es' => (string) $category->name_es,
                'name_en' => (string) $category->name_en,
            ];
        }
    }

    public function save(): void
    {
        $this->authorize('create', Program::class);

        foreach ($this->rows as $id => $data) {
            $category = ProgramCategory::query()->find($id);
            if ($category === null) {
                continue;
            }
            $category->name_es = trim($data['name_es']);
            $category->name_en = trim($data['name_en']) ?: null;
            $category->save();
        }

        session()->flash('status', __('Categorias actualizadas.'));
    }

    public function addCategory(): void
    {
        $this->authorize('create', Program::class);

        $validated = $this->validate([
            'newNameEs' => ['required', 'string', 'max:120'],
        ]);

        ProgramCategory::query()->create([
            'name_es' => $validated['newNameEs'],
            'slug' => Str::slug($validated['newNameEs']).'-'.Str::lower(Str::random(4)),
        ]);

        $this->newNameEs = '';
        $this->loadRows();
        session()->flash('status', __('Categoria creada.'));
    }

    public function render(): View
    {
        return view('catalog::livewire.categories.manage', [
            'categories' => ProgramCategory::query()->orderBy('name_es')->get(),
        ]);
    }
}
