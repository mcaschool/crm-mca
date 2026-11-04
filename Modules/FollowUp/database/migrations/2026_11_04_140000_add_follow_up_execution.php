<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_execution_switches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 20);
            $table->unsignedBigInteger('subject_id');
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('daily_limit')->default(1);
            $table->timestamp('verified_until')->nullable();
            $table->string('evidence_reference')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'subject_id']);
        });
        Schema::table('follow_up_actions', function (Blueprint $table): void {
            $table->foreignId('social_message_id')->nullable()->unique()->constrained('social_messages')->nullOnDelete();
            $table->uuid('execution_token')->nullable()->unique();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('dispatch_at')->nullable()->index();
            $table->timestamp('execution_queued_at')->nullable();
            $table->string('execution_error', 64)->nullable();
            $table->unsignedInteger('approved_draft_version')->nullable();
            $table->char('approved_content_hash', 64)->nullable();
            $table->timestamp('approval_expires_at')->nullable();
            $table->longText('execution_parameters')->nullable();
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_actions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('social_message_id');
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn(['execution_token', 'claimed_at', 'dispatch_at', 'execution_queued_at', 'execution_error', 'approved_draft_version', 'approved_content_hash', 'approval_expires_at', 'execution_parameters']);
        });
        Schema::dropIfExists('follow_up_execution_switches');
    }
};
