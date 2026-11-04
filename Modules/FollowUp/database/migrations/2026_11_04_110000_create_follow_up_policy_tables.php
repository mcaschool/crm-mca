<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->unique()->constrained('institutions')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('mode', 24)->default('manual');
            $table->unsignedSmallInteger('first_follow_up_hours')->default(24);
            $table->unsignedSmallInteger('minimum_interval_hours')->default(24);
            $table->unsignedTinyInteger('maximum_attempts')->default(3);
            $table->json('allowed_days');
            $table->time('business_hours_start')->default('09:00:00');
            $table->time('business_hours_end')->default('18:00:00');
            $table->string('timezone', 64)->default('UTC');
            $table->json('allowed_channels');
            $table->string('policy_version', 32)->default('2.0');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('follow_up_advisor_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('first_follow_up_hours')->nullable();
            $table->unsignedSmallInteger('minimum_interval_hours')->nullable();
            $table->unsignedTinyInteger('maximum_attempts')->nullable();
            $table->json('allowed_days')->nullable();
            $table->time('business_hours_start')->nullable();
            $table->time('business_hours_end')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->json('allowed_channels')->nullable();
            $table->boolean('pause_on_human')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['institution_id', 'bot_id'], 'follow_up_advisor_unique');
        });

        Schema::create('follow_up_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('follow_up_opportunity_id')->constrained('follow_up_opportunities')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('status', 24)->default('open');
            $table->string('classification', 48);
            $table->string('priority', 16)->default('low');
            $table->unsignedTinyInteger('score')->default(0);
            $table->json('reason_codes');
            $table->json('context_snapshot')->nullable();
            $table->foreignId('source_first_message_id')->nullable()->constrained('social_messages')->nullOnDelete();
            $table->foreignId('source_last_message_id')->nullable()->constrained('social_messages')->nullOnDelete();
            $table->unsignedInteger('previous_attempts')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('detected_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 64)->nullable();
            $table->timestamps();

            $table->unique(['follow_up_opportunity_id', 'sequence'], 'follow_up_cycle_sequence_unique');
            $table->index(['institution_id', 'status'], 'follow_up_cycle_inst_status_idx');
        });

        Schema::create('communication_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('social_conversation_id')->nullable()->constrained('social_conversations')->cascadeOnDelete();
            $table->string('subject_key', 64);
            $table->string('channel', 32);
            $table->string('purpose', 48);
            $table->string('state', 16)->default('unknown');
            $table->string('source', 64)->nullable();
            $table->text('evidence')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('policy_version', 32)->default('2.0');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['institution_id', 'subject_key', 'channel', 'purpose'], 'communication_consent_current_unique');
            $table->index(['institution_id', 'state'], 'communication_consent_inst_state_idx');
        });

        Schema::create('communication_consent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('communication_consent_id')->constrained('communication_consents')->cascadeOnDelete();
            $table->string('from_state', 16)->nullable();
            $table->string('to_state', 16);
            $table->string('source', 64)->nullable();
            $table->text('evidence')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('policy_version', 32);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['institution_id', 'communication_consent_id'], 'consent_event_inst_consent_idx');
        });

        Schema::create('follow_up_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('follow_up_opportunity_id')->constrained('follow_up_opportunities')->cascadeOnDelete();
            $table->foreignId('follow_up_cycle_id')->constrained('follow_up_cycles')->cascadeOnDelete();
            $table->string('type', 32)->default('commercial_follow_up');
            $table->string('channel', 32);
            $table->string('purpose', 48);
            $table->string('status', 24)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('policy_status', 16);
            $table->string('policy_reason_code', 64);
            $table->string('policy_version', 32);
            $table->unsignedInteger('eligibility_version')->default(1);
            $table->char('idempotency_key', 64);
            $table->boolean('approval_required')->default(true);
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('block_reason', 64)->nullable();
            $table->foreignId('social_whatsapp_template_id')->nullable()->constrained('social_whatsapp_templates')->nullOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->unsignedInteger('cycle_attempt_number');
            $table->timestamps();

            $table->unique(['institution_id', 'idempotency_key'], 'follow_up_action_idempotency_unique');
            $table->index(['institution_id', 'status', 'scheduled_at'], 'follow_up_action_inst_status_schedule_idx');
            $table->index(['follow_up_opportunity_id', 'status'], 'follow_up_action_opportunity_status_idx');
        });

        Schema::create('follow_up_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('follow_up_opportunity_id')->nullable()->constrained('follow_up_opportunities')->cascadeOnDelete();
            $table->foreignId('follow_up_cycle_id')->nullable()->constrained('follow_up_cycles')->cascadeOnDelete();
            $table->foreignId('follow_up_action_id')->nullable()->constrained('follow_up_actions')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 48);
            $table->string('actor_type', 24)->default('system');
            $table->string('reason_code', 64)->nullable();
            $table->string('policy_status', 16)->nullable();
            $table->string('policy_version', 32)->nullable();
            $table->json('safe_data')->nullable();
            $table->string('dedupe_key', 96)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['institution_id', 'dedupe_key'], 'follow_up_event_dedupe_unique');
            $table->index(['institution_id', 'follow_up_opportunity_id', 'occurred_at'], 'follow_up_event_opportunity_idx');
        });

        DB::statement('ALTER TABLE communication_consents ADD CONSTRAINT communication_consents_subject_check CHECK ((contact_id IS NULL) <> (social_conversation_id IS NULL))');
        DB::statement("ALTER TABLE communication_consents ADD CONSTRAINT communication_consents_state_check CHECK (state IN ('unknown','granted','denied','withdrawn'))");

        DB::table('follow_up_opportunities')->orderBy('id')->each(function (object $opportunity): void {
            DB::table('follow_up_cycles')->insert([
                'institution_id' => $opportunity->institution_id,
                'follow_up_opportunity_id' => $opportunity->id,
                'sequence' => max(1, (int) $opportunity->cycle),
                'status' => $opportunity->status === 'open' ? 'open' : $opportunity->status,
                'classification' => $opportunity->classification,
                'priority' => $opportunity->priority,
                'score' => $opportunity->score,
                'reason_codes' => $opportunity->reason_codes,
                'context_snapshot' => $opportunity->context_snapshot,
                'source_first_message_id' => $opportunity->source_last_message_id,
                'source_last_message_id' => $opportunity->source_last_message_id,
                'previous_attempts' => 0,
                'started_at' => $opportunity->detected_at,
                'detected_at' => $opportunity->detected_at,
                'ended_at' => $opportunity->status === 'open' ? null : $opportunity->cancelled_at,
                'end_reason' => $opportunity->cancellation_reason,
                'created_at' => $opportunity->created_at,
                'updated_at' => $opportunity->updated_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_events');
        Schema::dropIfExists('follow_up_actions');
        Schema::dropIfExists('communication_consent_events');
        Schema::dropIfExists('communication_consents');
        Schema::dropIfExists('follow_up_cycles');
        Schema::dropIfExists('follow_up_advisor_settings');
        Schema::dropIfExists('follow_up_settings');
    }
};
