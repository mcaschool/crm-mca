<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `course_idnumber` = idnumber del curso en Moodle (distinto del `code` interno
 * MC-001…). Se usa para ENLAZAR un programa recomendado que llega por el endpoint
 * InCompany (n8n) con el programa real del catálogo y mostrar su NOMBRE en la ficha.
 *
 * Nullable: hoy está vacío; se puebla con la correspondencia programa↔idnumber de
 * Moodle que aporta el cliente (importador del Excel o comando `catalog:set-idnumbers`).
 * Único por institución cuando está presente (MySQL permite varios NULL), para que
 * un idnumber no apunte a dos programas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->string('course_idnumber', 100)->nullable()->after('code');
            $table->unique(['institution_id', 'course_idnumber']);
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropUnique(['institution_id', 'course_idnumber']);
            $table->dropColumn('course_idnumber');
        });
    }
};
