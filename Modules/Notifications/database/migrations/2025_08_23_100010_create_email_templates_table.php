<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repositorio de plantillas de correo. Cada plantilla tiene nombre, asunto y cuerpo
 * (HTML del editor, con etiquetas dinámicas [Nombre]/[Área]… que se resuelven al
 * enviar). Dos tipos según `user_id`:
 *   - COMPARTIDA  (user_id = NULL): la ve/usa todo el equipo que envía; solo el
 *     Administrador la crea/edita/borra.
 *   - PROPIA      (user_id = usuario): privada de su creador; solo él la ve y gestiona.
 * Siempre acotada por `institution_id` (motor multi-institución).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            // NULL = compartida (del equipo); con valor = propia (privada de su dueño).
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name', 150);        // nombre de la plantilla (para elegirla)
            $table->string('subject', 200);     // asunto (admite etiquetas dinámicas)
            $table->longText('body');           // cuerpo HTML del editor (sanitizado al guardar)
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['institution_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
