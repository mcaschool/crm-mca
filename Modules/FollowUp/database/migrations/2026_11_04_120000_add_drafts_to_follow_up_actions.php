<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_actions', function (Blueprint $table): void {
            $table->string('draft_state', 24)->default('empty')->index();
            $table->unsignedInteger('draft_version')->default(0);
            $table->longText('draft_payload')->nullable();
            $table->char('context_fingerprint', 64)->nullable();
            $table->uuid('draft_reservation')->nullable();
            $table->timestamp('draft_reserved_at')->nullable();
            $table->timestamp('draft_generated_at')->nullable();
            $table->timestamp('draft_expires_at')->nullable();
            $table->string('draft_error', 64)->nullable();
            $table->foreignId('draft_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('draft_edited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_actions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('draft_edited_by');
            $table->dropColumn(['draft_state', 'draft_version', 'draft_payload', 'context_fingerprint', 'draft_reservation', 'draft_reserved_at', 'draft_generated_at', 'draft_expires_at', 'draft_error', 'draft_edited_at']);
        });
    }
};
