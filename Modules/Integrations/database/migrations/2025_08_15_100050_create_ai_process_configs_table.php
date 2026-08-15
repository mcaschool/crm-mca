<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_process_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            // null = configuracion por defecto de la institucion.
            $table->foreignId('bot_id')->nullable()->constrained('bots')->cascadeOnDelete();
            $table->string('process', 30); // conversation/classification/summary/email_draft
            $table->foreignId('integration_id')->constrained('integrations')->restrictOnDelete();
            $table->string('model', 100);
            $table->json('params')->nullable(); // temperatura, max_tokens, timeout
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['institution_id', 'bot_id', 'process']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_process_configs');
    }
};
