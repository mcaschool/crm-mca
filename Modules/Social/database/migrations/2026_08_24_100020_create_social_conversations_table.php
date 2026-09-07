<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversación de un canal social (un hilo con un contacto). Bloque 1: solo esquema.
 * `provider` se denormaliza para ícono/filtro sin join. Acotada por institución.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('social_channel_id')->constrained('social_channels')->cascadeOnDelete();
            $table->string('provider')->index();               // denormalizado (ícono/filtro)
            $table->string('external_conversation_id');        // wa_id / psid / ig thread id
            $table->string('contact_name')->nullable();
            $table->string('contact_external_id')->nullable();
            $table->string('contact_avatar_url')->nullable();
            $table->string('status')->default('open')->index(); // open | closed | pending
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['social_channel_id', 'external_conversation_id'], 'social_conv_channel_ext_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_conversations');
    }
};
