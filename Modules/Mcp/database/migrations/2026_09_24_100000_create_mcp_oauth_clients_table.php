<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidad OAuth PÚBLICA de un cliente (Dynamic Client Registration, RFC 7591).
 * Solo public clients: NO se emite ni guarda client_secret. Registrarse NO otorga
 * ningún acceso al CRM; la autorización operativa ocurre en /authorize (consentimiento
 * de admin) y se enlaza a mcp_clients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id', 64)->unique();     // público (mcpc_...)
            $table->string('client_name')->nullable();
            $table->json('redirect_uris');                  // lista validada (HTTPS + host permitido)
            $table->json('grant_types');                    // authorization_code, refresh_token
            $table->string('token_endpoint_auth_method', 20)->default('none');
            $table->string('scope')->nullable();            // scopes solicitados en el registro (informativo)
            // Enlace idempotente al contexto operativo autorizado (mcp_clients). Se fija en
            // el primer /authorize aprobado y se reutiliza en reautorizaciones/refresh mientras
            // ese mcp_client siga activo (no se crea un mcp_client nuevo por cada consentimiento).
            $table->unsignedBigInteger('mcp_client_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_clients');
    }
};
