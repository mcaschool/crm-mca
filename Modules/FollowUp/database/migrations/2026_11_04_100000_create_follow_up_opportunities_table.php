<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('bot_id')->constrained('bots')->restrictOnDelete();
            $table->foreignId('social_channel_id')->constrained('social_channels')->cascadeOnDelete();
            $table->foreignId('social_conversation_id')->constrained('social_conversations')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('source_last_message_id')->nullable()->constrained('social_messages')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('status', 24)->default('open'); // open | cancelled | suppressed
            $table->string('classification', 48);
            $table->string('priority', 16)->default('low');
            $table->unsignedTinyInteger('score')->default(0);
            $table->json('reason_codes');
            $table->json('context_snapshot')->nullable(); // solo senales, nunca texto de mensajes
            $table->unsignedInteger('cycle')->default(1);
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamp('source_last_message_at');
            $table->timestamp('detected_at');
            $table->timestamp('last_evaluated_at');
            $table->timestamp('next_evaluation_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 64)->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'social_conversation_id'], 'follow_up_inst_conversation_unique');
            $table->index(['institution_id', 'status', 'priority'], 'follow_up_inst_status_priority_idx');
            $table->index(['institution_id', 'bot_id', 'status'], 'follow_up_inst_bot_status_idx');
            $table->index(['institution_id', 'next_evaluation_at'], 'follow_up_inst_next_eval_idx');
            $table->index(['institution_id', 'contact_id', 'status'], 'follow_up_inst_contact_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_opportunities');
    }
};
