<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('type', 30);           // ai_provider/google/n8n/mailrelay/smtp/stripe/moodle
            $table->string('provider', 40)->nullable(); // openai/gemini/anthropic/...
            $table->string('name', 120);
            // Secretos CIFRADOS (encrypted:array). Unico lugar donde viven.
            $table->text('config')->nullable();
            // Version enmascarada, SIN cifrar (no contiene secreto). Lo que ve el panel.
            $table->json('config_preview')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 255)->nullable(); // saneado, nunca crudo
            $table->timestamps();

            $table->unique(['institution_id', 'type', 'name']);
            $table->index(['institution_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
