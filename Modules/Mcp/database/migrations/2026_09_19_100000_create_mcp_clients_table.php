<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes del servidor MCP (credenciales Bearer). El token NUNCA se guarda en
 * claro: solo su SHA-256 (se muestra una única vez al emitirlo con
 * `php artisan mcp:client create`). institution_id null = cliente GLOBAL
 * (trabaja transversalmente entre instituciones); con valor = acotado a esa
 * institución. Tabla de infraestructura de autenticación: sin scope global de
 * institución (como users), el aislamiento lo aplica el contexto MCP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('token_hash', 64)->unique(); // sha256 del token, nunca el token
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_clients');
    }
};
