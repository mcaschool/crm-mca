<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telemetría GLOBAL de IA (append-only). Registra TODA llamada al proveedor, genere o
 * no un mensaje de CRM (messages.meta cubre solo la conversación). NO guarda prompts,
 * respuestas, API keys ni secretos: solo métricas normalizadas + contexto de ejecución.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('institution_id')->index();
            $table->string('process', 40)->index();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('bot_id')->nullable()->index();
            $table->unsignedBigInteger('integration_id')->index();
            $table->string('provider', 40);
            $table->string('model', 100);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('uncached_input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status', 16)->index();            // success | error
            $table->string('error_category', 40)->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
