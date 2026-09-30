<?php

declare(strict_types=1);

namespace Modules\Catalog\Console;

use Illuminate\Console\Command;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramLine;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Fase 2 — Backfill de la categoría de FORMACIÓN. Asegura la categoría "Microcredenciales"
 * en program_lines (idempotente por slug) y asigna su line_id a los programas que aún no
 * tienen categoría de formación (todos los del catálogo actual son Microcredenciales).
 *
 * IDEMPOTENTE: solo rellena los que están en NULL, así correrlo dos veces no duplica ni
 * reasigna nada (57 antes → 57 después, nunca 114). No toca category_id (áreas),
 * course_idnumber ni code. Reporta cuántos actualizó y el conteo final por línea.
 */
class BackfillLinesCommand extends Command
{
    private const MICRO_SLUG = 'microcredenciales';

    protected $signature = 'catalog:backfill-lines {--institution= : ID de institución (por defecto, la única)}';

    protected $description = 'Asigna la categoría de formación "Microcredenciales" a los programas sin línea (idempotente).';

    public function handle(CurrentInstitution $context): int
    {
        $institutionId = $this->option('institution') !== null
            ? (int) $this->option('institution')
            : $context->runGlobally(fn () => Institution::query()->count() === 1 ? (int) Institution::query()->value('id') : null);

        if ($institutionId === null) {
            $this->error('Hay varias instituciones (o ninguna): indica --institution=ID.');

            return self::FAILURE;
        }

        return $context->runFor($institutionId, function (): int {
            // 1) Asegura la categoría de formación "Microcredenciales" (idempotente por slug).
            $micro = ProgramLine::query()->firstOrCreate(
                ['slug' => self::MICRO_SLUG],
                ['name_es' => 'Microcredenciales', 'name_en' => 'Microcredentials', 'display_order' => 0, 'status' => 'active'],
            );

            // 2) Asigna line_id SOLO a los programas sin categoría de formación (backfill).
            $updated = Program::query()->withTrashed()->whereNull('line_id')->update(['line_id' => $micro->getKey()]);

            $this->info("Programas asignados a «Microcredenciales»: {$updated}");

            // 3) Conteo final por categoría de formación.
            $this->line('Conteo por categoría de formación:');
            foreach (ProgramLine::query()->orderBy('name_es')->get() as $line) {
                $n = Program::query()->withTrashed()->where('line_id', $line->getKey())->count();
                $this->line("  {$line->name_es}: {$n}");
            }
            $sinLinea = Program::query()->withTrashed()->whereNull('line_id')->count();
            $this->line("  (sin categoría de formación): {$sinLinea}");

            return self::SUCCESS;
        });
    }
}
