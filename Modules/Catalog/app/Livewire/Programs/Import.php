<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire\Programs;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Services\PanelCatalogImporter;
use RuntimeException;

/**
 * Importación de catálogo desde el panel (Fase 3). Solo Admin (ProgramPolicy). Flujo en 3
 * etapas: SUBIR → VISTA PREVIA (nada se aplica) → CONFIRMAR (upsert por course_id) →
 * RESUMEN. La vista previa muestra el detalle fila por fila (crear/actualizar/qué cambia,
 * categorías nuevas y errores). Idempotente: reimportar no duplica.
 */
#[Layout('layouts.app')]
class Import extends Component
{
    use WithFileUploads;

    public mixed $file = null;

    public string $stage = 'upload';

    /** @var array<string, mixed> */
    public array $preview = [];

    /** @var array<string, int> */
    public array $result = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Program::class);
    }

    /** Al subir el archivo: valida, analiza y muestra la vista previa (sin escribir nada). */
    public function updatedFile(PanelCatalogImporter $importer): void
    {
        $this->authorize('create', Program::class);
        $this->reset('preview', 'result');
        $this->stage = 'upload';

        $this->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120'],
        ], [], ['file' => 'archivo']);

        if (! $this->file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $rows = $importer->parse($this->file->getRealPath(), $this->file->getClientOriginalExtension());
            $this->preview = $importer->analyze($rows);
            $this->stage = 'preview';
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());
            $this->reset('file');
        }
    }

    /** Confirma: re-lee el archivo temporal y APLICA el upsert. Muestra el resumen final. */
    public function confirm(PanelCatalogImporter $importer): void
    {
        $this->authorize('create', Program::class);

        if (! $this->file instanceof TemporaryUploadedFile) {
            $this->addError('file', 'El archivo ya no está disponible; vuelve a subirlo.');
            $this->stage = 'upload';

            return;
        }

        try {
            $rows = $importer->parse($this->file->getRealPath(), $this->file->getClientOriginalExtension());
            $this->result = $importer->apply($rows);
            $this->stage = 'done';
            $this->reset('file', 'preview');
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());
        }
    }

    /** Vuelve a empezar (subir otro archivo). */
    public function startOver(): void
    {
        $this->reset('file', 'preview', 'result');
        $this->stage = 'upload';
    }

    public function render(): View
    {
        return view('catalog::livewire.programs.import');
    }
}
