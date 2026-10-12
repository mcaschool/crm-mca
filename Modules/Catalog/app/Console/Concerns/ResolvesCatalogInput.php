<?php

declare(strict_types=1);

namespace Modules\Catalog\Console\Concerns;

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Archivo del Excel e institución de los comandos del catálogo (catalog:import y
 * catalog:import-knowledge). En modo «una institución por instalación» resuelve sola la única;
 * con varias exige --institution=ID.
 */
trait ResolvesCatalogInput
{
    /** El archivo tal cual, relativo a la raíz del proyecto o relativo a storage/app. */
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
