<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase A: presets y perfiles de acceso del cliente MCP. Un SOLO servidor MCP;
 * ChatGPT/Claude/Claude Code son tipos de cliente + perfil, no servidores
 * distintos.
 *  - assistant_type: chatgpt | claude | claude_code | generic (etiqueta de UI).
 *  - profile: inspection (solo lectura) | technical (lectura técnica completa).
 *  - allow_write: la ESCRITURA es siempre EXPLÍCITA. Por defecto FALSE, también
 *    para los clientes legacy: un cliente técnico inspecciona, pero no ejecuta
 *    herramientas de escritura salvo que un administrador lo habilite.
 *  - auth_kind: bearer (actual) | oauth (Fase B, aún no implementado).
 * Estado seguro por defecto: generic / technical / allow_write=false / bearer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_clients', function (Blueprint $table) {
            $table->string('assistant_type', 20)->default('generic')->after('name');
            $table->string('profile', 20)->default('technical')->after('assistant_type');
            $table->boolean('allow_write')->default(false)->after('profile'); // escritura = explícita
            $table->string('auth_kind', 10)->default('bearer')->after('allow_write');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_clients', function (Blueprint $table) {
            $table->dropColumn(['assistant_type', 'profile', 'allow_write', 'auth_kind']);
        });
    }
};
