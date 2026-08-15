<?php

declare(strict_types=1);

/**
 * Tapon de la fuga #3: `withoutGlobalScope(s)` desactiva el aislamiento por
 * institucion. Solo se permite dentro de Modules/Core (bootstrap auditado del
 * contexto y helpers de tenancy). En cualquier otro modulo es un ERROR.
 *
 * Este test recorre el codigo fuente de los modulos y falla si encuentra la
 * llamada fuera de Core.
 */
it('prohibe withoutGlobalScope(s) fuera de Modules/Core', function () {
    $modulesPath = dirname(__DIR__, 3).'/Modules';

    $offenders = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        // Core es el unico lugar autorizado (contexto, InstitutionScope, forInstitution).
        if (str_contains($path, '/Modules/Core/')) {
            continue;
        }

        // No auditar las propias pruebas de aislamiento (usan el bypass a proposito).
        if (str_contains($path, '/tests/')) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (preg_match('/withoutGlobalScope(s)?\s*\(/', $contents) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe(
        [],
        'withoutGlobalScope(s) solo se permite en Modules/Core. Infractores: '.implode(', ', $offenders)
    );
});
