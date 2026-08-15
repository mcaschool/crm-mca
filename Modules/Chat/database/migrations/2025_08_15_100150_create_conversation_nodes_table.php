<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
            $table->string('key', 60); // identificador estable usado por el codigo
            // message/menu/program_list/form/action/start_celia/external_link
            $table->string('type', 30);
            $table->text('content_es')->nullable();
            $table->text('content_en')->nullable();
            $table->json('config')->nullable(); // filtros de program_list, campos de form
            $table->smallInteger('display_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['institution_id', 'bot_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_nodes');
    }
};
