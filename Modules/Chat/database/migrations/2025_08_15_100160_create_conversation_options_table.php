<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('node_id')->constrained('conversation_nodes')->cascadeOnDelete();
            $table->string('label_es', 150);
            $table->string('label_en', 150)->nullable();
            $table->foreignId('target_node_id')->nullable()->constrained('conversation_nodes')->nullOnDelete();
            $table->string('action', 50)->nullable();
            $table->string('event_type', 60)->nullable();
            $table->smallInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['node_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_options');
    }
};
