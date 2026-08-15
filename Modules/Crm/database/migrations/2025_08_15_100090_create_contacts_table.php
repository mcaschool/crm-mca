<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80)->nullable(); // captura minima = nombre + correo
            $table->string('email', 190);
            $table->string('phone', 30)->nullable();
            $table->char('country', 2)->nullable(); // ISO-3166-1 alpha-2
            $table->string('preferred_language', 2)->default('es');
            // D2 (RGPD): consentimiento y baja desde el dia 1.
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_source', 60)->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            // Invariante: identidad unica por institucion + correo.
            $table->unique(['institution_id', 'email']);
            $table->index(['institution_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
