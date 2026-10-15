<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collab_chat_clocks', function (Blueprint $t): void {
            $t->foreignId('institution_id')->primary()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('tick')->default(0);
        });
        Schema::create('collab_chat_teams', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('description', 1000)->default('');
            $t->boolean('archived')->default(false);
            $t->unsignedBigInteger('access_version')->default(1);
            $t->timestamps(6);
            $t->index(['institution_id', 'id']);
        });
        Schema::create('collab_chat_team_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('team_id')->constrained('collab_chat_teams')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('role', 16)->default('member');
            $t->boolean('active')->default(true);
            $t->unsignedBigInteger('from_tick');
            $t->unsignedBigInteger('generation')->default(1);
            $t->timestamps(6);
            $t->unique(['team_id', 'user_id']);
            $t->index(['institution_id', 'user_id', 'active'], 'chat_team_access');
        });
        Schema::table('collab_chat_conversations', function (Blueprint $t): void {
            $t->unsignedBigInteger('first_user_id')->nullable()->change();
            $t->unsignedBigInteger('second_user_id')->nullable()->change();
            $t->string('type', 16)->default('direct');
            $t->string('name', 120)->nullable();
            $t->string('description', 1000)->default('');
            $t->foreignUlid('team_id')->nullable()->constrained('collab_chat_teams')->cascadeOnDelete();
            $t->string('visibility', 16)->default('private');
            $t->string('default_key', 16)->nullable();
            $t->boolean('archived')->default(false);
            $t->unique(['team_id', 'default_key'], 'chat_general_unique');
            $t->index(['institution_id', 'team_id', 'visibility'], 'chat_channels');
        });
        Schema::table('collab_chat_participants', function (Blueprint $t): void {
            $t->string('role', 16)->default('member');
            $t->unsignedBigInteger('from_tick')->default(0);
            $t->unsignedBigInteger('generation')->default(1);
        });
        Schema::table('collab_chat_messages', function (Blueprint $t): void {
            $t->unsignedBigInteger('access_tick')->default(0);
            $t->index(['conversation_id', 'access_tick', 'sequence'], 'chat_access_history');
        });
        Schema::create('collab_chat_event_access', function (Blueprint $t): void {
            $t->ulid('event_id')->primary();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('conversation_id')->constrained('collab_chat_conversations')->cascadeOnDelete();
            $t->unsignedBigInteger('access_tick');
        });
        Schema::create('collab_chat_space_commands', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('command_id', 64)->charset('ascii')->collation('ascii_bin');
            $t->char('request_hash', 64);
            $t->ulid('resource_id');
            $t->unique(['institution_id', 'user_id', 'command_id'], 'chat_space_command');
        });
        Schema::create('collab_chat_membership_history', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->string('scope', 16);
            $t->ulid('resource_id');
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('tick');
            $t->string('role', 16);
            $t->boolean('active');
            $t->timestamp('created_at', 6);
            $t->index(['institution_id', 'resource_id', 'tick'], 'chat_membership_audit');
        });
    }

    public function down(): void
    {
        // Refuse destructive rollback once spaces exist; restore from an approved backup instead.
        if (\Illuminate\Support\Facades\DB::table('collab_chat_conversations')->where('type', '!=', 'direct')->exists()) {
            throw new RuntimeException('Cannot roll back TeamChat spaces with persisted history.');
        }
        foreach (['collab_chat_membership_history', 'collab_chat_space_commands', 'collab_chat_event_access'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('collab_chat_messages', function (Blueprint $t): void {
            $t->dropIndex('chat_access_history');
            $t->dropColumn('access_tick');
        });
        Schema::table('collab_chat_participants', fn (Blueprint $t) => $t->dropColumn(['role', 'from_tick', 'generation']));
        Schema::table('collab_chat_conversations', function (Blueprint $t): void {
            $t->dropForeign(['team_id']);
            $t->dropUnique('chat_general_unique');
            $t->dropIndex('chat_channels');
            $t->dropColumn(['type', 'name', 'description', 'team_id', 'visibility', 'default_key', 'archived']);
            $t->unsignedBigInteger('first_user_id')->nullable(false)->change();
            $t->unsignedBigInteger('second_user_id')->nullable(false)->change();
        });
        Schema::dropIfExists('collab_chat_team_members');
        Schema::dropIfExists('collab_chat_teams');
        Schema::dropIfExists('collab_chat_clocks');
    }
};
