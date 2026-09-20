<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de TODA llamada tools/call del servidor MCP (actor = MCP): qué
 * herramienta, qué acción/recurso, con qué parámetros (SANEADOS: nunca
 * secretos), resultado y correlation id. institution_id nullable: las
 * operaciones globales/transversales no pertenecen a una institución.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcp_client_id')->constrained('mcp_clients')->cascadeOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->restrictOnDelete();
            $table->uuid('correlation_id')->index();
            $table->string('tool', 60)->index();
            $table->string('action', 120)->nullable();   // p. ej. operación de crm_execute o modelo afectado
            $table->string('resource', 191)->nullable(); // p. ej. Crm.Lead#42, tabla, ruta…
            $table->json('params')->nullable();          // argumentos saneados (sin secretos)
            $table->string('status', 20);                // ok | error
            $table->text('error')->nullable();           // mensaje de error saneado
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->index(['mcp_client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_logs');
    }
};
