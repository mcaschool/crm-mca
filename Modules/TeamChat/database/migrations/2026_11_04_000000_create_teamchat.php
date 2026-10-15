<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collab_chat_conversations', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignId('first_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('second_user_id')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('message_sequence')->default(0);
            $t->unsignedBigInteger('event_version')->default(0);
            $t->unsignedBigInteger('access_version')->default(1);
            $t->timestamp('activity_at', 6);
            $t->timestamps(6);
            $t->unique(['institution_id', 'first_user_id', 'second_user_id'], 'chat_direct_pair');
            $t->index(['institution_id', 'activity_at', 'id'], 'chat_activity');
        });
        Schema::create('collab_chat_participants', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('conversation_id')->constrained('collab_chat_conversations')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->timestamps(6);
            $t->unique(['conversation_id', 'user_id'], 'chat_member');
            $t->index(['institution_id', 'user_id', 'active'], 'chat_user_membership');
        });
        Schema::create('collab_chat_messages', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('conversation_id')->constrained('collab_chat_conversations')->cascadeOnDelete();
            $t->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('sequence');
            $t->string('command_id', 64)->charset('ascii')->collation('ascii_bin');
            $t->char('request_hash', 64);
            $t->text('body');
            $t->timestamp('created_at', 6);
            $t->unique(['conversation_id', 'sequence'], 'chat_message_sequence');
            $t->unique(['institution_id', 'sender_id', 'command_id'], 'chat_message_command');
            $t->index(['institution_id', 'conversation_id', 'sequence'], 'chat_history');
        });
        Schema::create('collab_chat_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('conversation_id')->constrained('collab_chat_conversations')->cascadeOnDelete();
            $t->foreignUlid('message_id')->constrained('collab_chat_messages')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamp('delivered_at', 6);
            $t->timestamp('read_at', 6)->nullable();
            $t->unique(['message_id', 'user_id'], 'chat_receipt');
            $t->index(['institution_id', 'user_id', 'conversation_id'], 'chat_receipts_user');
        });
    }

    public function down(): void
    {
        foreach (['collab_chat_receipts', 'collab_chat_messages', 'collab_chat_participants', 'collab_chat_conversations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
