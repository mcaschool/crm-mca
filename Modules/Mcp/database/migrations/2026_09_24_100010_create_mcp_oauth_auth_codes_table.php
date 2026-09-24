<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authorization codes (Authorization Code + PKCE). De UN SOLO USO y TTL corto. Guardan
 * solo el SHA-256 del código. Ligados a client_id, redirect_uri, resource, scope,
 * code_challenge (S256) y al mcp_client autorizado durante el consentimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_auth_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code_hash', 64)->unique();       // sha256(code)
            $table->string('client_id', 64)->index();        // mcp_oauth_clients.client_id
            $table->unsignedBigInteger('mcp_client_id');     // contexto operativo autorizado
            $table->unsignedBigInteger('user_id');           // admin que consintió
            $table->string('redirect_uri', 500);
            $table->string('code_challenge', 128);           // S256 (base64url)
            $table->string('scope');                         // scopes concedidos
            $table->string('resource', 500);                 // RFC 8707 (audience)
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();        // marca de un-solo-uso
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_auth_codes');
    }
};
