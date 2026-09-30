<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire\Programs;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramLine;

/**
 * Listado del catálogo (Fase 4): filtro por categoría de formación, activar/desactivar,
 * reordenar, y gestión del ciclo de vida — ARCHIVAR (borrado suave), RESTAURAR y ELIMINAR
 * DEFINITIVO (con confirmación). Muestra los dos ejes (categoría de formación + área).
 * Aislado por el scope global de Program. Gating por ProgramPolicy (solo Admin).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    /** Filtro por categoría de formación (line_id) o null = todas. */
    public ?int $lineFilter = null;

    /** Mostrar los archivados (borrado suave) en vez de los activos. */
    public bool $showArchived = false;

    /** Programa pendiente de ELIMINAR DEFINITIVO (para la confirmación). */
    public ?int $deletingId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Program::class);
    }

    public function updatedShowArchived(): void
    {
        $this->deletingId = null;
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

    /** Archiva (borrado suave): sale de la lista activa; se puede restaurar. */
    public function archive(int $programId): void
    {
        $program = Program::query()->findOrFail($programId);
        $this->authorize('delete', $program);
        $program->delete();
    }

    /** Restaura un programa archivado. */
    public function restore(int $programId): void
    {
        $program = Program::withTrashed()->findOrFail($programId);
        $this->authorize('delete', $program);
        $program->restore();
    }

    /** Pide confirmación antes de eliminar definitivamente. */
    public function confirmDelete(int $programId): void
    {
        $program = Program::withTrashed()->findOrFail($programId);
        $this->authorize('delete', $program);
        $this->deletingId = $programId;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    /** ELIMINA DEFINITIVAMENTE (sin vuelta atrás), solo tras la confirmación. */
    public function deleteForever(): void
    {
        if ($this->deletingId === null) {
            return;
        }
        $program = Program::withTrashed()->findOrFail($this->deletingId);
        $this->authorize('delete', $program);
        $program->forceDelete();
        $this->deletingId = null;

        session()->flash('status', __('Programa eliminado definitivamente.'));
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
            ->when($this->showArchived, fn ($q) => $q->onlyTrashed())
            ->when($this->lineFilter !== null, fn ($q) => $q->where('line_id', $this->lineFilter))
            ->with(['category', 'line'])
            ->orderBy('display_order')
            ->orderBy('name_es')
            ->get();
    }

    public function render(): View
    {
        $deleting = $this->deletingId !== null
            ? Program::withTrashed()->find($this->deletingId)
            : null;

        return view('catalog::livewire.programs.index', [
            'programs' => $this->orderedPrograms(),
            'lines' => ProgramLine::query()->orderBy('display_order')->orderBy('name_es')->get(),
            'deleting' => $deleting,
        ]);
    }
}
