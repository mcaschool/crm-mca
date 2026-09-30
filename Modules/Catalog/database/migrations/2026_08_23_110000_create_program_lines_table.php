<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorías de FORMACIÓN (líneas del catálogo): Microcredenciales, Programas Ejecutivos,
 * Diplomas Avanzados, etc. Es un eje SEPARADO de las "áreas" temáticas (program_categories):
 * un programa tendrá categoría (línea) Y área a la vez. Esta migración solo CREA la tabla
 * (vacía) y su gestión; el enlace de los programas (programs.line_id) y la asignación de los
 * 57 existentes llega en la Fase 2, con respaldo. Nombre bilingüe por columnas _es/_en.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('name_es', 120);
            $table->string('name_en', 120)->nullable();
            $table->string('slug', 80);
            $table->smallInteger('display_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['institution_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_lines');
    }
};
