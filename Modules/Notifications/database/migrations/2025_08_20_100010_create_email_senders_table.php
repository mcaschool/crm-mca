<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remitentes de correo saliente (Fase 1). Cada remitente tiene un nombre visible,
 * una direccion (@mcaschool.education) y sus PROPIAS credenciales SMTP guardadas
 * CIFRADAS en `config` (encrypted:array); `config_preview` guarda la version
 * enmascarada que muestra el panel. Mismo patron de secretos que `integrations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_senders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('name', 120);           // nombre visible / "De" (ej. "Finanzas MCA")
            $table->string('from_address', 190);   // finanzas@mcaschool.education
            $table->text('config')->nullable();          // SMTP cifrado: host/port/username/password/encryption
            $table->json('config_preview')->nullable();  // version enmascarada (sin secretos)
            $table->string('status', 20)->default('active');
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'from_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_senders');
    }
};
