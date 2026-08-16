<?php

declare(strict_types=1);

namespace Modules\Chat\Support;

/**
 * Mapeo determinista seniority + educacion -> nivel del programa (logica de
 * negocio confirmada del emparejador; no cambiar sin indicacion).
 *
 * - estudiante/inicio      -> inicial
 * - desarrollo/mando_medio -> intermedio
 * - directivo/empresario   -> avanzado
 * - La educacion SOLO afina a la baja: si no completo universidad (o mas), baja
 *   un escalon (p. ej. desarrollo=intermedio sin universitario completo -> inicial).
 */
final class LevelMapper
{
    public static function resolve(string $seniority, string $educacion): string
    {
        $map = (array) config('crm.matcher.seniority_level', []);
        $order = (array) config('crm.matcher.levels_order', ['inicial', 'intermedio', 'avanzado']);
        $educacionAlta = (array) config('crm.matcher.educacion_alta', []);

        $base = $map[$seniority] ?? 'inicial';

        // Educacion baja el nivel un escalon (nunca lo sube).
        if (! in_array($educacion, $educacionAlta, true)) {
            $index = array_search($base, $order, true);
            if ($index !== false && $index > 0) {
                $base = $order[$index - 1];
            }
        }

        return $base;
    }
}
