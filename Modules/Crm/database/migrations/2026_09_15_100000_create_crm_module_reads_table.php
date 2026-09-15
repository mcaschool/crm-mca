<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de "visto" POR USUARIO para los badges del menú lateral (Leads/Contactos),
 * siguiendo el patrón del badge de la Bandeja social pero con lectura individual:
 * un Lead visto por un usuario no significa que otro ya lo revisó.
 *
 * Modelo watermark: una fila por (usuario, módulo) con last_seen_at; "nuevo" =
 * created_at > last_seen_at. Entrar al módulo avanza la marca (contador → 0).
 * Es una sola fila por usuario/módulo (no una fila por registro): barata y con
 * índice único para el upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_module_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module', 20); // leads | contacts
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['user_id', 'module']);
            $table->index(['institution_id', 'module']);
        });

        // Apoyo del COUNT del badge de Leads: contacts ya tiene (institution_id,
        // created_at); leads solo lo tenía combinado con status/bot_id.
        Schema::table('leads', function (Blueprint $table) {
            $table->index(['institution_id', 'created_at'], 'leads_institution_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_institution_created_idx');
        });
        Schema::dropIfExists('crm_module_reads');
    }
};
