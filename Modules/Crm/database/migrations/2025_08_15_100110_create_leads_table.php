<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained('bots')->restrictOnDelete();
            $table->string('product_type', 40)->default('microcredential');
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('area', 80)->nullable();
            $table->string('goal', 80)->nullable();
            $table->string('level', 40)->nullable();
            $table->string('source', 60)->nullable();
            $table->string('status', 30)->default('new'); // new/contacted/qualified/enrolled/discarded
            $table->string('interest_level', 20)->default('low'); // low/medium/high
            $table->timestamps();

            $table->index(['institution_id', 'status', 'created_at']);
            $table->index(['institution_id', 'contact_id']);
            $table->index(['institution_id', 'bot_id', 'created_at']);
            $table->index('program_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
