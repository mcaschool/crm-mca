<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire\Knowledge\Concerns;

use Modules\Ai\Support\KnowledgeTaxonomy;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Services\ProgramProvisioningService;

/**
 * Biblioteca → «Programa Académico»: alta manual de programas del CATÁLOGO (la misma tabla
 * programs que alimenta el selector) sin salir de la pantalla. «+ Añadir programa» (individual,
 * deja el nuevo programa elegido en el selector) e «Importar» (masivo: revisar y luego crear).
 *
 * Toda acción exige ProgramPolicy::create en el servidor (ocultar el botón no basta); las
 * reglas de alta y duplicados viven en ProgramProvisioningService.
 */
trait ManagesCatalogPrograms
{
    public bool $showAddProgram = false;

    public string $newProgramName = '';

    public string $newProgramCode = '';

    public string $newProgramLine = '';

    /** Área (id) — solo en Microcredenciales. */
    public string $newProgramArea = '';

    public string $newProgramUrl = '';

    /** Programa INACTIVO que coincide con el alta: se ofrece activarlo en vez de duplicarlo. */
    public ?int $inactiveMatchId = null;

    public bool $showImportPrograms = false;

    public string $importText = '';

    /** @var array<string, mixed> Revisión del alta masiva (filas + resumen); vacía = sin revisar. */
    public array $importPreview = [];

    /** @var array<string, int> Resultado del alta masiva (created/duplicate/error). */
    public array $importResult = [];

    public function openAddProgram(): void
    {
        $this->authorize('create', Program::class);
        $this->resetAddProgram();
        // Parte de la línea ya elegida en el espacio «Programa Académico».
        $this->newProgramLine = $this->programLine;
        $this->showAddProgram = true;
    }

    public function closeAddProgram(): void
    {
        $this->resetAddProgram();
    }

    public function createProgram(ProgramProvisioningService $provisioning): void
    {
        $this->authorize('create', Program::class);
        $this->resetErrorBag();
        $this->inactiveMatchId = null;

        $outcome = $provisioning->create([
            'name' => $this->newProgramName,
            'code' => $this->newProgramCode,
            'line' => $this->newProgramLine,
            'area' => $this->newProgramArea,
            'url' => $this->newProgramUrl,
        ], ProgramProvisioningService::METHOD_MANUAL);

        if ($outcome['status'] !== 'created' || $outcome['program'] === null) {
            foreach ($outcome['errors'] as $field => $message) {
                $this->addError('newProgram'.ucfirst($field), $message);
            }
            if ($outcome['status'] === 'inactive' && $outcome['existing'] !== null) {
                $this->inactiveMatchId = (int) $outcome['existing']->getKey();
            }

            return;
        }

        $this->selectCreatedProgram($outcome['program']);
        session()->flash('status', __('Programa «:name» creado y seleccionado. Ya puedes subir su ficha.', ['name' => $outcome['program']->name_es]));
    }

    /** Activa el programa inactivo que coincidía con el alta (en lugar de duplicarlo). */
    public function activateMatchedProgram(ProgramProvisioningService $provisioning): void
    {
        $program = $this->inactiveMatchId !== null ? Program::query()->find($this->inactiveMatchId) : null;
        if ($program === null) {
            return;
        }
        $this->authorize('update', $program);

        $provisioning->activate($program, ProgramProvisioningService::METHOD_MANUAL);
        $this->selectCreatedProgram($program);
        session()->flash('status', __('Programa «:name» activado y seleccionado.', ['name' => $program->name_es]));
    }

    public function openImportPrograms(): void
    {
        $this->authorize('create', Program::class);
        $this->reset(['importText', 'importPreview', 'importResult']);
        $this->showImportPrograms = true;
    }

    public function closeImportPrograms(): void
    {
        $this->reset(['showImportPrograms', 'importText', 'importPreview', 'importResult']);
    }

    /** Paso 1: revisar sin escribir (válidos, duplicados, errores). */
    public function previewImport(ProgramProvisioningService $provisioning): void
    {
        $this->authorize('create', Program::class);
        $this->importResult = [];
        $this->validate(['importText' => ['required', 'string']], ['importText.required' => __('Pega al menos una fila.')]);

        $this->importPreview = $provisioning->previewBulk($this->importText);
    }

    /** Paso 2: crear solo las filas válidas (se revisa otra vez en el servidor). */
    public function confirmImport(ProgramProvisioningService $provisioning): void
    {
        $this->authorize('create', Program::class);
        if (trim($this->importText) === '') {
            return;
        }

        $result = $provisioning->importBulk($this->importText);
        $this->importResult = ['created' => $result['created'], 'duplicate' => $result['duplicate'], 'error' => $result['error']];
        $this->importPreview = ['rows' => $result['rows'], 'summary' => $this->importPreview['summary'] ?? [], 'truncated' => $this->importPreview['truncated'] ?? false];

        session()->flash('status', __(':created programa(s) creado(s), :duplicate ya existían, :error omitido(s) por errores.', $this->importResult));
    }

    private function selectCreatedProgram(Program $program): void
    {
        if (array_key_exists((string) $program->line, KnowledgeTaxonomy::programLines())) {
            $this->programLine = (string) $program->line;
        }
        $this->programAuto = false;
        $this->programSearch = '';
        $this->programId = (string) $program->getKey();
        $this->resetAddProgram();
    }

    private function resetAddProgram(): void
    {
        $this->reset(['showAddProgram', 'newProgramName', 'newProgramCode', 'newProgramLine', 'newProgramArea', 'newProgramUrl', 'inactiveMatchId']);
        $this->resetErrorBag(['newProgramName', 'newProgramCode', 'newProgramLine', 'newProgramArea', 'newProgramUrl']);
    }
}
