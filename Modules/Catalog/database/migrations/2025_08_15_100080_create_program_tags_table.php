<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('tag', 50);
            $table->timestamps();

            $table->unique(['program_id', 'tag']);
            $table->index(['institution_id', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_tags');
    }
};
