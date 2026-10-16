<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collab_chat_uploads', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->ulid('conversation_id');
            $t->foreign('conversation_id')->references('id')->on('collab_chat_conversations')->cascadeOnDelete();
            $t->ulid('message_id')->nullable();
            $t->foreign('message_id')->references('id')->on('collab_chat_messages')->cascadeOnDelete();
            $t->string('command_id', 64)->charset('ascii')->collation('ascii_bin');
            $t->char('request_hash', 64);
            $t->string('name', 180);
            $t->string('kind', 16);
            $t->string('mime', 120);
            $t->unsignedBigInteger('expected_size');
            $t->char('source_hash', 64);
            $t->string('state', 16)->default('prepared');
            $t->string('scan_status', 24)->default('not_scanned');
            $t->unsignedBigInteger('access_from_tick');
            $t->unsignedBigInteger('size')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['institution_id', 'user_id', 'command_id'], 'chat_upload_command');
            $t->index(['institution_id', 'state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collab_chat_uploads');
    }
};
