<?php

declare(strict_types=1);

namespace Modules\Catalog\Console;

use Illuminate\Console\Command;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Carga puntual de los `course_idnumber` de Moodle en el catálogo, a partir de un
 * CSV con la correspondencia REAL programa↔idnumber que aporta el cliente. NO inventa
 * idnumbers: solo escribe los que vienen en el archivo.
 *
 * Formato CSV (con o sin cabecera): dos columnas → code, course_idnumber
 *   MC-001,CORP-LEAD-101
 *   MC-002,CORP-DATA-204
 *
 * Idempotente: re-ejecutar sobrescribe. Un `code` inexistente se reporta y no falla.
 */
class SetCourseIdnumbersCommand extends Command
{
    protected $signature = 'catalog:set-idnumbers {file : Ruta al CSV (columnas: code,course_idnumber)} {--institution= : ID de institución (opcional si solo hay una)}';

    protected $description = 'Puebla programs.course_idnumber (idnumber de Moodle) desde un CSV code,idnumber.';

    public function handle(CurrentInstitution $context): int
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

        $rows = $this->readCsv($path);
        if ($rows === []) {
            $this->error('El CSV no tiene filas de datos.');

            return self::FAILURE;
        }

        $set = 0;
        $notFound = [];

        $context->runFor($institutionId, function () use ($rows, &$set, &$notFound): void {
            foreach ($rows as [$code, $idnumber]) {
                $program = Program::withTrashed()->where('code', $code)->first();
                if ($program === null) {
                    $notFound[] = $code;

                    continue;
                }
                $program->course_idnumber = $idnumber;
                $program->save();
                $set++;
            }
        });

        $this->info("Idnumbers asignados: {$set}");
        if ($notFound !== []) {
            $this->warn('Códigos no encontrados en el catálogo ('.count($notFound).'): '.implode(', ', $notFound));
        }

        return self::SUCCESS;
    }

    /**
     * Lee el CSV a pares [code, idnumber]. Descarta filas vacías y una cabecera
     * (si la primera columna es literalmente "code"/"id").
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function readCsv(string $path): array
    {
        $out = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        try {
            $first = true;
            while (($cells = fgetcsv($handle)) !== false) {
                $code = trim((string) ($cells[0] ?? ''));
                $idnumber = trim((string) ($cells[1] ?? ''));

                if ($first) {
                    $first = false;
                    if (in_array(mb_strtolower($code), ['code', 'id', 'codigo', 'código'], true)) {
                        continue; // salta cabecera
                    }
                }

                if ($code === '' || $idnumber === '') {
                    continue;
                }
                $out[] = [$code, $idnumber];
            }
        } finally {
            fclose($handle);
        }

        return $out;
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
}
