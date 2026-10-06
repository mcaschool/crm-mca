<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valoración del equipo sobre las respuestas del asesor en el modo de prueba («Correcta» /
 * «Necesita mejora» + observación). Es EVIDENCIA para corregir fuentes o instrucciones a
 * conciencia: nada se aplica solo al prompt ni a la base de conocimiento. Una valoración por
 * respuesta (la última sustituye a la anterior).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->unique()->constrained('messages')->cascadeOnDelete();
            $table->string('rating', 20); // correct | needs_improvement
            $table->text('comment')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['institution_id', 'bot_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_feedback');
    }
};
