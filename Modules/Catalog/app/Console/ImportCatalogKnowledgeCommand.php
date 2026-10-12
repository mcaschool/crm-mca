<?php

declare(strict_types=1);

namespace Modules\Catalog\Console;

use Illuminate\Console\Command;
use Modules\Ai\Services\MicrocredentialKnowledgeImporter;
use Modules\Catalog\Console\Concerns\ResolvesCatalogInput;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use RuntimeException;

/**
 * Conocimiento académico de las microcredenciales desde el Excel del catálogo: una fuente
 * «programa_academico» por programa (MicrocredentialKnowledgeImporter) y, con --advisor, su
 * asignación a ese asesor. No toca programas, etiquetas, áreas ni bot_program (eso es
 * catalog:import y el recomendador).
 *
 *   php artisan catalog:import-knowledge storage/app/catalog/catalogo.xlsx --advisor=microcredenciales --dry-run
 *   php artisan catalog:import-knowledge storage/app/catalog/catalogo.xlsx --advisor=microcredenciales
 *
 * --advisor es el slug del asesor (único por institución), nunca un id. --dry-run muestra qué se
 * crearía, actualizaría, desactivaría y asignaría, y la normalización de la base general, sin
 * escribir nada.
 */
class ImportCatalogKnowledgeCommand extends Command
{
    use ResolvesCatalogInput;

    protected $signature = 'catalog:import-knowledge
        {file : Ruta al Excel del catálogo (.xlsx, hoja "Catalogo")}
        {--institution= : ID de institución (opcional si solo hay una)}
        {--advisor= : Slug del asesor al que asignar las fichas (p. ej. microcredenciales)}
        {--dry-run : Previsualiza sin escribir nada}';

    protected $description = 'Crea/actualiza una fuente de conocimiento por microcredencial desde el Excel del catálogo (y la asigna al asesor indicado).';

    public function handle(CurrentInstitution $context, MicrocredentialKnowledgeImporter $importer): int
    {
        $path = $this->resolvePath((string) $this->argument('file'));
        if ($path === null) {
            $this->error('No se encontró el archivo indicado.');

            return self::FAILURE;
        }
        $institutionId = $this->resolveInstitution($context);
        if ($institutionId === null) {
            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $slug = $this->option('advisor');

        return $context->runFor($institutionId, function () use ($importer, $path, $institutionId, $dryRun, $slug): int {
            $advisor = null;
            if ($slug !== null && $slug !== '') {
                $advisor = Bot::query()->where('slug', (string) $slug)->first();
                if ($advisor === null) {
                    $this->error("No existe un asesor con slug «{$slug}» en la institución #{$institutionId}. No se hizo nada.");

                    return self::FAILURE;
                }
            }

            $this->info(($dryRun ? 'PREVISUALIZACIÓN (no se escribe nada)' : 'Importación').' · institución #'.$institutionId
                .' · asesor: '.($advisor !== null ? $advisor->assistant_name.' ('.$advisor->slug.')' : '— (sin asignación)'));

            try {
                $report = $importer->import($path, $advisor, $dryRun);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->printReport($report);

            return self::SUCCESS;
        });
    }

    /** @param  array<string, mixed>  $report */
    private function printReport(array $report): void
    {
        $verb = $report['dry_run'] ? ' (se haría)' : '';
        $this->newLine();
        $this->line('<options=bold>Fichas de microcredenciales'.$verb.'</>');
        $this->line('  Creadas:       '.count($report['created']));
        $this->line('  Actualizadas:  '.count($report['updated']));
        $this->line('  Sin cambios:   '.count($report['unchanged']));
        $this->line('  Desactivadas (programa inactivo): '.count($report['deactivated']).$this->codes($report['deactivated']));
        $this->line('  Conservadas desactivadas (decisión manual): '.count($report['kept_inactive']).$this->codes($report['kept_inactive']));
        $this->line('  Rechazadas:    '.count($report['rejected']));
        foreach ($report['rejected'] as $r) {
            $this->line('    - '.($r['row'] > 0 ? 'fila '.$r['row'].' ' : '').($r['code'] !== '' ? $r['code'].': ' : '').$r['reason']);
        }

        $g = $report['general'];
        $this->newLine();
        $this->line('<options=bold>Base general '.MicrocredentialKnowledgeImporter::GENERAL_CODE.'</>');
        $this->line('  '.match ($g['status']) {
            'would_normalize' => 'Se normalizaría la prioridad de '.($g['from'] ?? '?').' a '.$g['to'].' en '.$g['path'].' (y se marcaría como de esta institución)',
            'normalized' => 'Prioridad normalizada de '.($g['from'] ?? '?').' a '.$g['to'].' en '.$g['path'].' (marcado con la institución y sincronizado)',
            'already' => 'Prioridad ya normalizada ('.$g['to'].') en '.$g['path'],
            'not_found' => 'NO se encontró su archivo .md: prioridad sin cambios (revisar)',
            'ambiguous' => 'Archivo ambiguo o de otra institución ('.$g['path'].'): prioridad sin cambios (revisar)',
            'outside_library' => 'Su archivo está fuera de biblioteca/ ('.$g['path'].'): prioridad sin cambios (revisar)',
            'no_metadata' => 'Su archivo no tiene comentario de metadatos ('.$g['path'].'): prioridad sin cambios (revisar)',
            'no_source_file' => 'Su fuente no tiene archivo registrado (source_file): prioridad sin cambios (revisar)',
            'shared' => 'Su archivo ('.$g['path'].') lo usa también otra institución: prioridad sin cambios (revisar)',
            default => $g['status'],
        });

        if ($report['advisor'] !== null) {
            $this->newLine();
            $this->line('<options=bold>Asignación al asesor '.$report['advisor'].$verb.'</>');
            $this->line('  Asignadas (nuevas, activas): '.count($report['assigned']));
            $this->line('  Ya asignadas: '.count($report['already_assigned']));
            $this->line('  Pausadas que se conservan pausadas: '.count($report['kept_paused']).$this->codes($report['kept_paused']));
        }
    }

    /** @param  list<string>  $codes */
    private function codes(array $codes): string
    {
        return $codes === [] ? '' : ' ('.implode(', ', $codes).')';
    }
}
