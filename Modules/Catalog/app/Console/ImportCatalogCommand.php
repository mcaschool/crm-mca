<?php

declare(strict_types=1);

namespace Modules\Catalog\Console;

use Illuminate\Console\Command;
use Modules\Catalog\Services\CatalogImporter;
use Modules\Catalog\Services\ImportReport;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Importa el catalogo desde el Excel del cliente. Idempotente (upsert por code).
 *
 * En modo "una institucion por instalacion" resuelve sola la unica institucion;
 * con varias, exige --institution=ID.
 */
class ImportCatalogCommand extends Command
{
    protected $signature = 'catalog:import {file : Ruta al archivo .xlsx} {--institution= : ID de institucion (opcional si solo hay una)}';

    protected $description = 'Importa/actualiza el catalogo de programas desde un Excel (hoja "Catalogo").';

    public function handle(CurrentInstitution $context, CatalogImporter $importer): int
    {
        $path = $this->resolvePath((string) $this->argument('file'));
        if ($path === null) {
            $this->error('No se encontro el archivo indicado.');

            return self::FAILURE;
        }

        $institutionId = $this->resolveInstitution($context);
        if ($institutionId === null) {
            return self::FAILURE;
        }

        $this->info("Importando catalogo para la institucion #{$institutionId}...");

        $report = $context->runFor($institutionId, fn (): ImportReport => $importer->import($path));

        $this->printReport($report);

        return self::SUCCESS;
    }

    private function resolvePath(string $file): ?string
    {
        foreach ([$file, base_path($file), storage_path('app/'.$file)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveInstitution(CurrentInstitution $context): ?int
    {
        $option = $this->option('institution');
        if ($option !== null) {
            return (int) $option;
        }

        /** @var array<int, int> $ids */
        $ids = $context->runGlobally(fn () => Institution::query()->pluck('id')->all());

        if (count($ids) === 1) {
            return (int) $ids[0];
        }

        $this->error('Hay '.count($ids).' instituciones; especifica --institution=ID.');

        return null;
    }

    private function printReport(ImportReport $report): void
    {
        $this->newLine();
        $this->line('<options=bold>Reporte de importacion</>');
        $this->line("  Creados:      {$report->created}");
        $this->line("  Actualizados: {$report->updated}");
        $this->line('  Incompletos (importados, marcados para revision): '.count($report->incomplete));
        $this->line('  Omitidos (no importados): '.count($report->skipped));

        if ($report->incomplete !== []) {
            $this->newLine();
            $this->line('<comment>Incompletos / necesitan revision:</comment>');
            foreach ($report->incomplete as $item) {
                $this->line("  - {$item['code']}: {$item['reason']}");
            }
        }

        if ($report->skipped !== []) {
            $this->newLine();
            $this->line('<comment>Omitidos:</comment>');
            foreach ($report->skipped as $item) {
                $this->line("  - fila {$item['row']}: {$item['reason']}");
            }
        }

        $this->newLine();
        $this->info("Total en catalogo procesado: {$report->totalProcessed()} programas.");
    }
}
