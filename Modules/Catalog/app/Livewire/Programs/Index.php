<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire\Programs;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /**
     * Mueve un programa una posición arriba (-1) o abajo (+1) en el orden del catálogo.
     *
     * Antes intercambiaba los display_order de dos filas; con valores repetidos (los 77
     * programas tenían 1) el intercambio no cambiaba nada. Ahora, dentro de una transacción
     * y con las filas bloqueadas, se toma el orden visible (display_order, nombre, id), se
     * renumera 1..n si hay huecos o repetidos y se intercambian las dos posiciones. En los
     * extremos no hace nada. Solo toca display_order, y solo de la institución activa
     * (scope global de Program).
     */
    private function swapOrder(int $programId, int $direction): void
    {
        $program = Program::query()->findOrFail($programId);
        $this->authorize('update', $program);

        DB::transaction(function () use ($program, $direction): void {
            /** @var Collection<int, Program> $ordered */
            $ordered = Program::query()
                ->orderBy('display_order')->orderBy('name_es')->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'institution_id', 'display_order', 'name_es'])
                ->values();

            $position = $ordered->search(fn (Program $p) => $p->getKey() === $program->getKey());
            if ($position === false || ! $ordered->has($position + $direction)) {
                return;
            }

            // Secuencia estable 1..n con el orden actual y las dos posiciones intercambiadas.
            $ids = $ordered->pluck('id')->all();
            [$ids[$position], $ids[$position + $direction]] = [$ids[$position + $direction], $ids[$position]];

            foreach ($ids as $index => $id) {
                $row = $ordered->firstWhere('id', $id);
                if ((int) $row->display_order !== $index + 1) {
                    Program::query()->whereKey($id)->update(['display_order' => $index + 1]);
                }
            }
        });
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
            ->orderBy('id')
            ->get();
    }

    public function render(): View
    {
        return view('catalog::livewire.programs.index', [
            'programs' => $this->orderedPrograms(),
        ]);
    }
}
