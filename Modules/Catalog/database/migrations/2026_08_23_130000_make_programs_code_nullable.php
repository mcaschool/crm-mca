<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 — El identificador del programa es course_idnumber; el `code` (MC-XXX) es
 * heredado y NO se inventa. Las altas desde el importador del panel (course_id, nombre,
 * categoría) no traen `code`, así que la columna pasa a ser NULLABLE. El índice único
 * (institution_id, code) sigue válido: MySQL trata cada NULL como distinto, así que puede
 * haber varios programas con code NULL sin colisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rellena los NULL con un valor único antes de volver a NOT NULL (para no romper
        // el índice único ni la restricción).
        DB::statement("UPDATE programs SET code = CONCAT('IMP-', id) WHERE code IS NULL");

        Schema::table('programs', function (Blueprint $table) {
            $table->string('code', 40)->nullable(false)->change();
        });
    }
};
