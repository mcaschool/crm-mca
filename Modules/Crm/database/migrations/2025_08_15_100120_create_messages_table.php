<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('sender_type', 10); // user/system/celia
            $table->mediumText('content');
            $table->string('message_type', 30)->default('text'); // text/menu/program_list/link/form
            // Metadatos por mensaje para el AI Deflection Rate: provider, model,
            // prompt_tokens, completion_tokens, latency_ms, cost_usd. Sin provider
            // = resuelto sin IA (cuenta a favor del deflection rate).
            $table->json('meta')->nullable();
            // Append-only: solo created_at.
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['conversation_id', 'id']);
            $table->index(['institution_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
