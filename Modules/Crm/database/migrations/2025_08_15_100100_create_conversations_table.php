<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            // null hasta que el usuario da nombre + correo.
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('bot_id')->constrained('bots')->restrictOnDelete();
            $table->char('session_id', 36)->unique(); // token opaco (localStorage)
            $table->string('channel', 20)->default('web');
            $table->string('mode', 10)->default('guided'); // guided/celia
            $table->string('language', 2)->default('es');
            $table->string('status', 20)->default('open'); // open/closed/abandoned
            // Recuperacion de sesion: nodo actual del arbol. FK se anade tras crear
            // conversation_nodes (dependencia circular resuelta en migracion posterior).
            $table->unsignedBigInteger('current_node_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'bot_id', 'last_activity_at']);
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
