<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\IncompanyLead;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Institution;

/**
 * Re-enlaza los programas de los leads InCompany ya ingresados: rellena el
 * `programa_N_program_id` guardado cuando está vacío pero el code SÍ existe hoy en el
 * catálogo (por code o course_idnumber). Útil para leads que entraron por una versión
 * del endpoint que solo comparaba course_idnumber. La ficha ya resuelve en vivo; esto
 * corrige el dato GUARDADO (reportes, "Recomendado" del emparejador). Idempotente.
 */
class RelinkIncompanyProgramsCommand extends Command
{
    protected $signature = 'crm:relink-incompany-programs {--institution= : ID de institución (por defecto, la activa/única)}';

    protected $description = 'Rellena el enlace guardado de programas en leads InCompany cuyo código sí existe en el catálogo.';

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
            $updated = 0;
            $leadsTouched = 0;

            IncompanyLead::query()->chunkById(200, function ($rows) use (&$updated, &$leadsTouched): void {
                foreach ($rows as $inc) {
                    $dirty = false;
                    $firstProgramId = null;

                    foreach ([1, 2, 3] as $i) {
                        $code = trim((string) $inc->{'programa_'.$i.'_code'});
                        $pid = $inc->{'programa_'.$i.'_program_id'};
                        if ($code !== '' && $pid === null) {
                            $program = Program::findByCourseIdnumberOrCode($code);
                            if ($program !== null) {
                                $inc->{'programa_'.$i.'_program_id'} = $program->getKey();
                                $dirty = true;
                                $updated++;
                            }
                        }
                        if ($i === 1 && $inc->programa_1_program_id !== null) {
                            $firstProgramId = $inc->programa_1_program_id;
                        }
                    }

                    if ($dirty) {
                        $inc->save();
                        $leadsTouched++;

                        // Alinea el program_id principal del lead con el primer programa.
                        if ($firstProgramId !== null) {
                            $lead = Lead::query()->find($inc->lead_id);
                            if ($lead !== null && $lead->program_id === null) {
                                $lead->program_id = $firstProgramId;
                                $lead->save();
                            }
                        }
                    }
                }
            });

            $this->info("Programas re-enlazados: {$updated} (en {$leadsTouched} leads InCompany).");

            return self::SUCCESS;
        });
    }
}
