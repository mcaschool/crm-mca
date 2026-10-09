<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Respuestas APROBADAS por el equipo desde «Probar asesor» (opción «Esta es la respuesta correcta»
 * de «Necesita mejora»). Se aplican al instante a las preguntas equivalentes del mismo asesor y
 * del mismo tema (AdvisorCorrections). Por institución y asesor; feedback_id enlaza la valoración
 * de origen (una corrección por valoración).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
            $table->foreignId('feedback_id')->nullable()->unique()->constrained('advisor_feedback')->nullOnDelete();
            $table->text('question');                       // la pregunta del usuario que se corrigió
            $table->string('topic_line', 40)->nullable();   // línea activa en ese momento (null = general)
            $table->text('answer');                         // la respuesta aprobada
            $table->boolean('active')->default(true);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['institution_id', 'bot_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_corrections');
    }
};
