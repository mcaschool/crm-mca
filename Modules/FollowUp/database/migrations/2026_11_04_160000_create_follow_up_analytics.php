<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_analytics_runs', function (Blueprint $table): void {
            $table->foreignId('institution_id')->primary()->constrained()->cascadeOnDelete();
            $table->uuid('generation')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('facts')->default(0);
            $table->string('version', 24)->default('7.1');
        });
        Schema::create('follow_up_analytics_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('source_key', 96);
            $table->unsignedBigInteger('opportunity_id')->nullable();
            $table->unsignedBigInteger('action_id')->nullable();
            $table->unsignedBigInteger('bot_id')->nullable();
            $table->unsignedBigInteger('program_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('subject', 80)->nullable();
            $table->string('channel', 24)->nullable();
            $table->string('priority', 16)->nullable();
            $table->string('stage', 32)->nullable();
            $table->string('opportunity_status', 32)->nullable();
            $table->string('status', 32)->nullable();
            $table->string('action_type', 32)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('eligible_at')->nullable();
            $table->timestamp('response_at')->nullable();
            $table->unsignedBigInteger('evidence_id')->nullable();
            $table->json('data');
            $table->uuid('generation');
            $table->unique(['institution_id', 'kind', 'source_key'], 'fu_analytics_source');
            $table->index(['institution_id', 'kind', 'occurred_at'], 'fu_analytics_period');
            $table->index(['institution_id', 'kind', 'eligible_at'], 'fu_analytics_execution');
            $table->index(['institution_id', 'subject', 'eligible_at'], 'fu_analytics_identity');
            $table->index(['institution_id', 'opportunity_id'], 'fu_analytics_opportunity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_analytics_facts');
        Schema::dropIfExists('follow_up_analytics_runs');
    }
};
