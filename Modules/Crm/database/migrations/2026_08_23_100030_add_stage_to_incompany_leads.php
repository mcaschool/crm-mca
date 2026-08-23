<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado del EMBUDO InCompany en el perfil (no en el pipeline global de leads): la
 * persona primero ve su diagnóstico (evento ruta_generada → stage 'diagnostico') y
 * luego, si lo pide, solicita contacto (evento solicita_contacto → stage
 * 'solicita_contacto', el lead más caliente). El upsert por email nunca degrada el
 * stage. Se guardan las marcas de tiempo de cada salto para ver el embudo en la ficha.
 *
 * Se añade un índice (institution_id, email) porque el upsert deduplica por email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incompany_leads', function (Blueprint $table) {
            $table->string('stage', 30)->default('diagnostico')->after('area_desarrollo');
            $table->timestamp('diagnostico_at')->nullable()->after('stage');
            $table->timestamp('solicita_contacto_at')->nullable()->after('diagnostico_at');

            $table->index(['institution_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('incompany_leads', function (Blueprint $table) {
            $table->dropIndex(['institution_id', 'email']);
            $table->dropColumn(['stage', 'diagnostico_at', 'solicita_contacto_at']);
        });
    }
};
