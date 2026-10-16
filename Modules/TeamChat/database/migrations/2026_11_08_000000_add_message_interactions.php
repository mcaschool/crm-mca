<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collab_chat_messages', function (Blueprint $t): void {
            $t->foreignUlid('reply_to_id')->nullable()->constrained('collab_chat_messages')->nullOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('edited_at', 6)->nullable();
            $t->timestamp('deleted_at', 6)->nullable();
        });
        foreach (['reactions', 'mentions', 'pins'] as $kind) {
            Schema::create('collab_chat_'.$kind, function (Blueprint $t) use ($kind): void {
                $t->id();
                $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
                $t->foreignUlid('message_id')->constrained('collab_chat_messages')->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->unsignedBigInteger('access_tick');
                $t->timestamps(6);
                if ($kind === 'reactions') {
                    $t->string('emoji', 32);
                    $t->unique(['message_id', 'user_id', 'emoji'], 'chat_reaction_unique');
                } elseif ($kind === 'mentions') {
                    $t->boolean('active')->default(true);
                    $t->unique(['message_id', 'user_id'], 'chat_mention_unique');
                } else {
                    $t->unique('message_id', 'chat_pin_unique');
                }
            });
        }
        Schema::create('collab_chat_message_commands', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('command_id', 64)->charset('ascii')->collation('ascii_bin');
            $t->char('request_hash', 64);
            $t->foreignUlid('message_id')->constrained('collab_chat_messages')->cascadeOnDelete();
            $t->timestamp('created_at', 6);
            $t->unique(['institution_id', 'user_id', 'command_id'], 'chat_interaction_command');
        });
        Schema::create('collab_chat_message_audit', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('message_id')->constrained('collab_chat_messages')->cascadeOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('operation', 16);
            $t->unsignedInteger('version');
            $t->char('before_hash', 64);
            $t->char('after_hash', 64);
            $t->timestamp('created_at', 6);
        });
    }

    public function down(): void
    {
        foreach (['message_audit', 'message_commands', 'pins', 'mentions', 'reactions'] as $kind) {
            Schema::dropIfExists('collab_chat_'.$kind);
        }
        Schema::table('collab_chat_messages', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('reply_to_id');
            $t->dropColumn(['version', 'edited_at', 'deleted_at']);
        });
    }
};
