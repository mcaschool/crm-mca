<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_messages', fn (Blueprint $t) => $t->index(['institution_id', 'social_conversation_id', 'direction', 'sender_type', 'provider_timestamp'], 'fu_message_window_idx'));
        foreach (['follow_up_settings', 'follow_up_advisor_settings'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->json('whatsapp_cadence')->nullable());
        }
        Schema::table('follow_up_actions', function (Blueprint $t): void {
            $t->string('cadence_key', 64)->nullable()->unique();
            $t->unsignedTinyInteger('cadence_slot')->nullable();
            $t->unsignedBigInteger('cadence_anchor_id')->nullable();
        });
    }

    public function down(): void
    {
        // InnoDB may remove the original implicit FK index when the composite
        // index becomes its replacement. Restore it before removing ours.
        if (! Schema::hasIndex('social_messages', 'social_messages_institution_id_foreign')) {
            Schema::table('social_messages', fn (Blueprint $t) => $t->index('institution_id', 'social_messages_institution_id_foreign'));
        }
        Schema::table('social_messages', fn (Blueprint $t) => $t->dropIndex('fu_message_window_idx'));
        Schema::table('follow_up_actions', function (Blueprint $t): void {
            $t->dropUnique(['cadence_key']);
            $t->dropColumn(['cadence_key', 'cadence_slot', 'cadence_anchor_id']);
        });
        foreach (['follow_up_settings', 'follow_up_advisor_settings'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('whatsapp_cadence'));
        }
    }
};
