<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centro de Conocimiento (Bloque 4c): programas del catálogo que cada asesor puede
 * recomendar. La presencia de la fila significa "asignado". El emparejador, sus opciones y
 * el saludo con programas vistos solo consideran los programas asignados al bot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_program', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['bot_id', 'program_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_program');
    }
};
