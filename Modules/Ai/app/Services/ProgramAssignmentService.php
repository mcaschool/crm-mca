<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Program;
use Modules\Institutions\Models\Bot;

/**
 * Programas del catálogo que cada asesor PUEDE RECOMENDAR (Centro de Conocimiento, Bloque 4c),
 * vía el pivote bot_program (la fila = asignado). Es la ÚNICA capa con la lógica de asignación
 * y con el filtro "solo asignados" que usan el emparejador, sus opciones y el saludo de Celia.
 * Todo respeta el scope de institución (las consultas de Program lo aplican).
 *
 * Área: el id de program_categories, o 'sin_area' para los programas sin área.
 */
class ProgramAssignmentService
{
    public const NO_AREA = 'sin_area';

    /**
     * Subconsulta de ids de programa asignados a un bot, para `whereIn('programs.id', …)`.
     * Si el bot no tiene asignaciones, el filtro no deja pasar ninguno.
     */
    public function assignedIdsQuery(int $botId): QueryBuilder
    {
        return DB::table('bot_program')->select('program_id')->where('bot_id', $botId);
    }

    /** @return array<int, true> program_id => true (asignados al bot) */
    public function assignedMap(Bot $bot): array
    {
        $ids = $this->assignedIdsQuery((int) $bot->getKey())
            ->pluck('program_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_fill_keys($ids, true);
    }

    /** Interruptor de un programa: lo asigna si no lo está; si lo está, lo quita. Devuelve el estado final. */
    public function toggle(Bot $bot, Program $program): bool
    {
        if (isset($this->assignedMap($bot)[(int) $program->getKey()])) {
            $bot->programs()->detach($program->getKey());

            return false;
        }

        $bot->programs()->syncWithoutDetaching([$program->getKey()]);

        return true;
    }

    /** Asigna todos los programas de un área (activos o no). Devuelve cuántos se consideraron. */
    public function assignArea(Bot $bot, string $area): int
    {
        return $this->assignIds($bot, $this->inArea($area)->pluck('id')->all());
    }

    /** Quita del bot todos los programas de un área. Devuelve cuántos se quitaron. */
    public function detachArea(Bot $bot, string $area): int
    {
        return $this->detachIds($bot, $this->inArea($area)->pluck('id')->all());
    }

    /** Asigna todos los programas que coinciden con la búsqueda (nombre o código). */
    public function assignMatching(Bot $bot, string $term): int
    {
        return trim($term) === '' ? 0 : $this->assignIds($bot, $this->catalog($term)->pluck('id')->all());
    }

    /** Quita del bot todos los programas que coinciden con la búsqueda (nombre o código). */
    public function detachMatching(Bot $bot, string $term): int
    {
        return trim($term) === '' ? 0 : $this->detachIds($bot, $this->catalog($term)->pluck('id')->all());
    }

    /**
     * Catálogo asignable (no borrado; activo o inactivo), filtrado por nombre o código.
     *
     * @return Builder<Program>
     */
    public function catalog(?string $term = null): Builder
    {
        $term = trim((string) $term);

        return Program::query()
            ->when($term !== '', function (Builder $q) use ($term): void {
                $like = '%'.$term.'%';
                $q->where(fn (Builder $w) => $w->where('code', 'like', $like)->orWhere('name_es', 'like', $like));
            })
            ->orderBy('code')
            ->orderBy('name_es');
    }

    /** @param  array<int, int|string>  $ids */
    private function assignIds(Bot $bot, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $bot->programs()->syncWithoutDetaching($ids);

        return count($ids);
    }

    /** @param  array<int, int|string>  $ids */
    private function detachIds(Bot $bot, array $ids): int
    {
        return $ids === [] ? 0 : $bot->programs()->detach($ids);
    }

    /** @return Builder<Program> */
    private function inArea(string $area): Builder
    {
        return $area === self::NO_AREA
            ? Program::query()->whereNull('category_id')
            : Program::query()->where('category_id', (int) $area);
    }
}
