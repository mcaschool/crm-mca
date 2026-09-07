<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canal social conectado (una cuenta de WhatsApp / Instagram / Messenger). Bloque 1:
 * solo esquema. Acotado por institución (regla de esquema del proyecto). Las credenciales
 * (token, waba_id, verify_token…) se guardan cifradas por el cast del modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('provider')->index();          // whatsapp | instagram | messenger
            $table->string('display_name');
            $table->string('external_id')->nullable();     // phone_number_id / ig_user_id / page_id
            // TEXT (no JSON): el cast encrypted:array del modelo guarda el secreto CIFRADO
            // como cadena, igual que integrations.config. Un JSON rechazaría el ciphertext.
            $table->text('credentials')->nullable();        // token, waba_id, verify_token, etc. (cifrado)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['institution_id', 'provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_channels');
    }
};
