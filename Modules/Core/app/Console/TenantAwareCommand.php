<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\CurrentInstitution;

/**
 * Comando base consciente de la institucion (tapon de la fuga #2: consola/scheduler).
 *
 * Un comando corre sin usuario autenticado, asi que no hay contexto de institucion.
 * Esta clase obliga a declararlo de forma EXPLICITA:
 *   - `--institution=ID`  -> ejecuta la logica una vez, con esa institucion activa.
 *   - `--all-institutions`-> itera todas, estableciendo el contexto en cada vuelta.
 * Sin ninguna de las dos, el comando se niega a correr (no adivina).
 *
 * Las subclases implementan handleForInstitution(); nunca leen el contexto a mano.
 */
abstract class TenantAwareCommand extends Command
{
    /**
     * Anade las opciones estandar a la firma del comando hijo.
     * El hijo debe incluir {--institution=} {--all-institutions} en su $signature,
     * o usar este helper documentado. Mantenemos la validacion aqui.
     */
    public function handle(CurrentInstitution $context): int
    {
        $institutionOption = $this->option('institution');
        $all = (bool) $this->option('all-institutions');

        if ($all && $institutionOption !== null) {
            $this->error('Usa --institution=ID o --all-institutions, no ambos.');

            return self::INVALID;
        }

        if (! $all && $institutionOption === null) {
            $this->error('Falta el contexto de institucion: pasa --institution=ID o --all-institutions.');

            return self::INVALID;
        }

        if ($all) {
            return $this->runForAllInstitutions($context);
        }

        $id = (int) $institutionOption;

        return $context->runFor($id, fn () => $this->handleForInstitution($id));
    }

    /**
     * Itera todas las instituciones activas. Se resuelve en modo global para
     * poder LEER la lista de instituciones sin filtro, y luego se fija el
     * contexto de cada una antes de ejecutar la logica.
     */
    protected function runForAllInstitutions(CurrentInstitution $context): int
    {
        /** @var array<int,int> $ids */
        $ids = $context->runGlobally(
            fn () => \Modules\Institutions\Models\Institution::query()->pluck('id')->all()
        );

        $exit = self::SUCCESS;

        foreach ($ids as $id) {
            $result = $context->runFor((int) $id, fn () => $this->handleForInstitution((int) $id));

            if ($result !== self::SUCCESS) {
                $exit = $result;
            }
        }

        return $exit;
    }

    /**
     * Logica del comando para UNA institucion, ya con el contexto activo.
     */
    abstract protected function handleForInstitution(int $institutionId): int;
}
