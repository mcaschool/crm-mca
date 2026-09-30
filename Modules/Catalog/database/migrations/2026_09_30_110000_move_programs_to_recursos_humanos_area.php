<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Migración de DATOS: nueva área «Recursos Humanos» (Microcredenciales) con 9 programas
 * movidos desde su área actual. Las asignaciones a agentes (bot_program) no se tocan.
 *
 * up(): en cada institución que tenga estos códigos, exige EXACTAMENTE los 9 (si falta alguno
 * falla antes de escribir nada), crea el área si no existe (por nombre, en esa institución),
 * guarda el área anterior de cada programa en program_area_moves y lo mueve. Todo en una
 * transacción. Idempotente: lo que ya está en el área no se vuelve a mover ni a respaldar.
 * Una instalación sin ninguno de estos códigos (BD nueva o de pruebas) no hace nada.
 *
 * down(): devuelve cada programa a su área anterior (solo si sigue en Recursos Humanos),
 * elimina el área si quedó vacía y borra el respaldo.
 */
return new class extends Migration
{
    public const AREA_ES = 'Recursos Humanos';

    public const AREA_EN = 'Human Resources';

    public const AREA_SLUG = 'recursos-humanos';

    /** Tabla de respaldo (área anterior de cada programa movido) y clave de este movimiento. */
    public const BACKUP = 'program_area_moves';

    public const MOVE = 'recursos_humanos';

    public const CODES = ['MC-002', 'MC-008', 'MC-010', 'MC-013', 'MC-014', 'MC-016', 'MC-019', 'MC-020', 'MC-056'];

    public function up(): void
    {
        $programs = DB::table('programs')->whereIn('code', self::CODES)->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'institution_id', 'code', 'category_id']);

        if ($programs->isEmpty()) {
            $this->report('programs.area.recursos_humanos: sin estos programas, nada que mover');

            return;
        }

        // Validación completa ANTES de escribir: los 9 códigos, una sola vez, por institución.
        foreach ($programs->groupBy('institution_id') as $institutionId => $rows) {
            $missing = array_values(array_diff(self::CODES, $rows->pluck('code')->all()));
            if ($missing !== [] || $rows->count() !== count(self::CODES)) {
                throw new RuntimeException(sprintf(
                    'Recursos Humanos: la institución %s debe tener exactamente %d programas (%d encontrados; faltan: %s). No se ha cambiado nada.',
                    $institutionId, count(self::CODES), $rows->count(), $missing === [] ? 'ninguno, hay duplicados' : implode(', ', $missing),
                ));
            }
        }

        if (! Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('institution_id')->index();
                $table->string('move', 60);
                $table->string('program_code', 40);
                $table->unsignedBigInteger('previous_category_id')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['institution_id', 'move', 'program_code']);
            });
        }

        $moved = DB::transaction(function () use ($programs): int {
            $moved = 0;
            foreach ($programs->groupBy('institution_id') as $institutionId => $rows) {
                $areaId = $this->areaId((int) $institutionId) ?? DB::table('program_categories')->insertGetId([
                    'institution_id' => $institutionId,
                    'name_es' => self::AREA_ES,
                    'name_en' => self::AREA_EN,
                    'slug' => self::AREA_SLUG,
                    'display_order' => 0,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($rows as $program) {
                    if ((int) $program->category_id === (int) $areaId) {
                        continue; // ya está en el área (re-ejecución)
                    }
                    DB::table(self::BACKUP)->insertOrIgnore([
                        'institution_id' => $institutionId,
                        'move' => self::MOVE,
                        'program_code' => $program->code,
                        'previous_category_id' => $program->category_id,
                        'created_at' => now(),
                    ]);
                    DB::table('programs')->where('id', $program->id)->update(['category_id' => $areaId, 'updated_at' => now()]);
                    $moved++;
                }

                $inArea = DB::table('programs')->where('institution_id', $institutionId)->whereNull('deleted_at')
                    ->whereIn('code', self::CODES)->where('category_id', $areaId)->count();
                if ($inArea !== count(self::CODES)) {
                    throw new RuntimeException("Recursos Humanos: se esperaban 9 programas en el área y hay {$inArea}.");
                }
            }

            return $moved;
        });

        $this->report("programs.area.recursos_humanos: {$moved} programas movidos a «".self::AREA_ES.'»');
    }

    public function down(): void
    {
        $restored = 0;
        $institutions = [];

        if (Schema::hasTable(self::BACKUP)) {
            $institutions = DB::table(self::BACKUP)->where('move', self::MOVE)->distinct()->pluck('institution_id')->all();

            $restored = DB::transaction(function (): int {
                $restored = 0;
                foreach (DB::table(self::BACKUP)->where('move', self::MOVE)->get() as $row) {
                    $areaId = $this->areaId((int) $row->institution_id);
                    if ($areaId === null) {
                        continue;
                    }
                    // Solo si sigue en Recursos Humanos: no pisa un cambio manual posterior.
                    $restored += DB::table('programs')
                        ->where('institution_id', $row->institution_id)->where('code', $row->program_code)
                        ->where('category_id', $areaId)
                        ->update(['category_id' => $row->previous_category_id, 'updated_at' => now()]);
                }
                DB::table(self::BACKUP)->where('move', self::MOVE)->delete();

                return $restored;
            });

            if (! DB::table(self::BACKUP)->exists()) {
                Schema::drop(self::BACKUP);
            }
        }

        // En las instituciones que movió up(), el área se elimina solo si quedó vacía (ningún
        // programa, ni borrado en blando, la usa).
        $areas = DB::table('program_categories')->whereIn('institution_id', $institutions)->where('name_es', self::AREA_ES)->pluck('id');
        foreach ($areas as $areaId) {
            if (! DB::table('programs')->where('category_id', $areaId)->exists()) {
                DB::table('program_categories')->where('id', $areaId)->delete();
            }
        }

        $this->report("programs.area.recursos_humanos (down): {$restored} programas devueltos a su área anterior");
    }

    private function areaId(int $institutionId): ?int
    {
        $id = DB::table('program_categories')->where('institution_id', $institutionId)
            ->where('name_es', self::AREA_ES)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function report(string $message): void
    {
        Log::info($message);
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo '  '.$message.PHP_EOL;
        }
    }
};
