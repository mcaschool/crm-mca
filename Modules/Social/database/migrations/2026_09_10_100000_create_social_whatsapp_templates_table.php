<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de WhatsApp (message templates de la WABA). Cada plantilla pertenece a un
 * SocialChannel provider=whatsapp (el WABA vive en credentials['waba_id'] del canal).
 * Acotada por institución (regla de esquema del proyecto). Los estados son strings
 * flexibles (Meta añade estados nuevos sin previo aviso): PENDING, APPROVED, REJECTED,
 * PAUSED, DISABLED, FLAGGED, ARCHIVED, DELETED, IN_APPEAL, LOCKED, LIMIT_EXCEEDED…
 * Aquí NUNCA se guardan tokens ni secretos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('social_channel_id')->constrained('social_channels')->cascadeOnDelete();
            $table->string('meta_template_id')->nullable()->index(); // id que asigna Meta al crearla
            // Meta admite nombres de hasta 512 (igual que el validator local); el string()
            // por defecto (VARCHAR 255) truncaría. language corto para que el índice único
            // compuesto quede holgado en utf8mb4 (8 + 2048 + 128 bytes < 3072 de InnoDB).
            $table->string('name', 512);                              // lowercase [a-z0-9_]
            $table->string('language', 32);                           // código Meta: es, es_MX, en_US…
            $table->string('category');                               // MARKETING | UTILITY | AUTHENTICATION
            $table->string('status')->index();                        // string flexible (ver docblock)
            $table->string('quality_score')->nullable();              // SOLO el score: GREEN | YELLOW | RED | UNKNOWN
            $table->json('quality_details')->nullable();              // date/reasons de quality_score (nunca la respuesta Graph completa)
            $table->string('parameter_format')->nullable();           // POSITIONAL | NAMED
            $table->json('components');                               // HEADER/BODY/FOOTER/BUTTONS tal como Meta
            $table->text('rejection_reason')->nullable();
            $table->json('rejection_details')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['social_channel_id', 'name', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_whatsapp_templates');
    }
};
