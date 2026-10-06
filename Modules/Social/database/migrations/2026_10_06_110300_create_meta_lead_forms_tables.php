<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formularios publicitarios de Facebook/Instagram (Meta Lead Ads) directos al CRM, sin
 * intermediarios. Preparado y DESACTIVADO: leer los leads requiere un permiso de Meta que aún no
 * está aprobado (ver config social.meta.lead_forms_enabled).
 *
 * meta_lead_forms: formularios de cada Página con su programa y asesor responsable.
 * meta_lead_receipts: un registro por lead de Meta (idempotencia por id de Meta + estado y
 * error legible), sin datos personales: esos viven en el contacto/lead creado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_lead_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('social_channel_id')->constrained('social_channels')->cascadeOnDelete();
            $table->string('form_id', 64);
            $table->string('name', 255);
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained('bots')->nullOnDelete();
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'form_id']);
        });

        Schema::create('meta_lead_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('leadgen_id', 64);
            $table->string('form_id', 64)->nullable();
            $table->string('page_id', 64)->nullable();
            $table->string('status', 20); // skipped | processed | failed
            $table->string('error', 255)->nullable();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->json('attribution')->nullable(); // campaña / conjunto / anuncio / plataforma
            $table->timestamps();

            $table->unique(['institution_id', 'leadgen_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_lead_receipts');
        Schema::dropIfExists('meta_lead_forms');
    }
};
