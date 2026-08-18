<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas internas de un lead (con autor y fecha). Append-only en la practica: el
 * equipo anade contexto; no se editan datos de negocio ajenos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // Autor de la nota (usuario del panel). nullOnDelete: si se borra el
            // usuario, la nota se conserva como registro historico.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name', 120)->nullable(); // copia del nombre para el historico
            $table->text('body');
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_notes');
    }
};
