<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensaje de una conversación social (entrante/saliente). Bloque 1: solo esquema.
 * Acotado por institución.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('social_conversation_id')->constrained('social_conversations')->cascadeOnDelete();
            $table->string('external_message_id')->nullable()->index();
            $table->string('direction');                    // inbound | outbound
            $table->string('type')->default('text');        // text|image|audio|video|document|sticker|other
            $table->text('body')->nullable();
            $table->json('attachments')->nullable();
            $table->string('status')->nullable();           // received|sent|delivered|read|failed
            $table->string('sender_type');                  // contact | agent
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('provider_timestamp')->nullable();
            $table->timestamps();

            $table->unique(['social_conversation_id', 'external_message_id'], 'social_msg_conv_ext_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_messages');
    }
};
