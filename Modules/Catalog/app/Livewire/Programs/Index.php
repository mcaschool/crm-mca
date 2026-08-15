<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire\Programs;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Catalog\Models\Program;

/**
 * Listado del catalogo: activar/desactivar y reordenar. Aislado por el scope
 * global de Program. Gating por ProgramPolicy (Admin y Marketing).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Program::class);
    }

    public function toggleActive(int $programId): void
    {
        $program = Program::query()->findOrFail($programId);
        $this->authorize('update', $program);

        $program->status = $program->status === 'active' ? 'inactive' : 'active';
        $program->save();
    }

    public function moveUp(int $programId): void
    {
        $this->swapOrder($programId, -1);
    }

    public function moveDown(int $programId): void
    {
        $this->swapOrder($programId, +1);
    }

    private function swapOrder(int $programId, int $direction): void
    {
        $program = Program::query()->findOrFail($programId);
        $this->authorize('update', $program);

        /** @var Collection<int, Program> $ordered */
        $ordered = $this->orderedPrograms()->values();
        $position = $ordered->search(fn (Program $p) => $p->getKey() === $program->getKey());

        if ($position === false) {
            return;
        }

        $target = $ordered->get($position + $direction);
        if (! $target instanceof Program) {
            return;
        }

        // Intercambia el display_order de ambos.
        $a = $program->display_order;
        $program->display_order = $target->display_order;
        $target->display_order = $a;
        $program->save();
        $target->save();
    }

    /**
     * @return Collection<int, Program>
     */
    private function orderedPrograms(): Collection
    {
        return Program::query()
            ->with('category')
            ->orderBy('display_order')
            ->orderBy('name_es')
            ->get();
    }

    public function render(): View
    {
        return view('catalog::livewire.programs.index', [
            'programs' => $this->orderedPrograms(),
        ]);
    }
}
