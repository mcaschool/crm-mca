<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained('bots')->restrictOnDelete();
            $table->string('source', 40); // matcher/menu/celia
            // Append-only: solo created_at. Sin UNIQUE: el interes repetido es senal.
            $table->timestamp('created_at')->nullable();

            $table->index(['institution_id', 'program_id', 'created_at']);
            $table->index(['contact_id', 'program_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_interests');
    }
};
