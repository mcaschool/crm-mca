<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Categoría de FORMACIÓN del programa: programs.line_id → program_lines.id.
 * ADITIVA y no destructiva: nullable, nullOnDelete. NO toca category_id (áreas temáticas),
 * course_idnumber (id Moodle, identificador) ni code (llave de upsert del importador).
 * La asignación de los programas existentes la hace el comando catalog:backfill-lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('line_id')->nullable()->after('category_id')
                ->constrained('program_lines')->nullOnDelete();
            $table->index(['institution_id', 'line_id']);
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropIndex(['institution_id', 'line_id']);
            $table->dropConstrainedForeignId('line_id');
        });
    }
};
