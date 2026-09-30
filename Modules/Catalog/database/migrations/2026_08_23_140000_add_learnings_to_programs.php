<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — "Aprendizajes" del programa (bilingüe), para el formulario de gestión manual.
 * Aditiva y nullable: no afecta a nada existente. El detalle/temario sigue en la web; esto
 * es un campo de contenido más del catálogo estructurado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->text('learnings_es')->nullable()->after('short_description_en');
            $table->text('learnings_en')->nullable()->after('learnings_es');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn(['learnings_es', 'learnings_en']);
        });
    }
};
