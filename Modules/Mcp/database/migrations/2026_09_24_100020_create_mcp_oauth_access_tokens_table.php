<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access tokens OPACOS (Bearer) de corta duración. Solo se guarda su SHA-256. Ligados
 * al client_id, al mcp_client (contexto operativo), scope y resource (audience). El
 * middleware valida hash + activo + expiración + resource; el permiso real lo impone
 * SIEMPRE el estado de mcp_client (profile/allow_write).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();      // sha256(access token)
            $table->string('client_id', 64)->index();
            $table->unsignedBigInteger('mcp_client_id')->index();
            $table->string('scope');
            $table->string('resource', 500);
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_access_tokens');
    }
};
