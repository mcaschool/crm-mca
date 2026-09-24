<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refresh tokens OPACOS (scope offline_access). Solo SHA-256. ROTATORIOS: al usarse se
 * revoca el anterior y se emite uno nuevo (rotated_to_id). Reusar un refresh ya rotado
 * es un indicio de fuga → se rechaza. Ligados al mcp_client, scope y resource.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();      // sha256(refresh token)
            $table->string('client_id', 64)->index();
            $table->unsignedBigInteger('mcp_client_id')->index();
            $table->string('scope');
            $table->string('resource', 500);
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();        // cuándo se rotó
            $table->unsignedBigInteger('rotated_to_id')->nullable(); // nuevo refresh emitido
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_refresh_tokens');
    }
};
