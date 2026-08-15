<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('code', 60);
            $table->string('type', 40); // faq/policy/procedure/general
            $table->string('category', 60)->nullable();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('url', 500)->nullable();
            $table->longText('content_es')->nullable();
            $table->longText('content_en')->nullable();
            $table->smallInteger('priority')->default(0); // orden de ensamblado (Forma A)
            $table->string('status', 20)->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'bot_id', 'code']);
            $table->index(['institution_id', 'bot_id', 'status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_sources');
    }
};
