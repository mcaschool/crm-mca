<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_opportunities', function (Blueprint $table): void {
            $table->foreignId('commercial_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('crm_conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('commercial_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('commercial_activity_id')->nullable()->constrained('crm_activities')->nullOnDelete();
            $table->foreignId('commercial_reviewed_message_id')->nullable()->constrained('social_messages')->nullOnDelete();
            $table->string('commercial_link_source', 24)->nullable();
            $table->foreignId('commercial_linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('commercial_linked_at')->nullable();
            $table->text('commercial_link_evidence')->nullable();
            $table->unsignedInteger('commercial_version')->default(0);
            $table->char('commercial_fingerprint', 64)->nullable();
            $table->json('commercial_recommendation')->nullable();
            $table->json('commercial_result')->nullable();
            $table->string('commercial_close_reason', 64)->nullable();
            $table->boolean('commercial_hold')->default(false)->index();
            $table->timestamp('commercial_updated_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_opportunities', function (Blueprint $table): void {
            foreach (['commercial_owner_id', 'crm_conversation_id', 'commercial_program_id', 'commercial_activity_id', 'commercial_linked_by', 'commercial_reviewed_message_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['commercial_link_source', 'commercial_linked_at', 'commercial_link_evidence', 'commercial_version', 'commercial_fingerprint', 'commercial_recommendation', 'commercial_result', 'commercial_close_reason', 'commercial_hold', 'commercial_updated_at']);
        });
    }
};
