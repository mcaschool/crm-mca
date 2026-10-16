<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collab_streams', function (Blueprint $t): void {
            $t->foreignId('institution_id')->primary()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('position')->default(0);
            $t->unsignedBigInteger('floor')->default(0);
        });
        Schema::create('collab_events', function (Blueprint $t): void {
            $t->string('id', 64)->charset('ascii')->collation('ascii_bin')->primary();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('position');
            $t->string('aggregate_type', 64)->charset('ascii')->collation('ascii_bin');
            $t->string('aggregate_id', 64)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('aggregate_version');
            $t->char('content_hash', 64);
            $t->json('envelope');
            $t->json('audience');
            $t->boolean('ephemeral');
            $t->timestamp('expires_at', 6)->nullable();
            $t->timestamp('created_at', 6);
            $t->unique(['institution_id', 'position']);
            $t->unique(['institution_id', 'aggregate_type', 'aggregate_id', 'aggregate_version'], 'collab_aggregate_version');
        });
        Schema::create('collab_outbox', function (Blueprint $t): void {
            $t->string('event_id', 64)->charset('ascii')->collation('ascii_bin')->primary();
            $t->foreign('event_id')->references('id')->on('collab_events')->cascadeOnDelete();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->string('status', 24)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('claim', 64)->nullable();
            $t->timestamp('lease_until', 6)->nullable();
            $t->timestamp('available_at', 6);
            $t->timestamp('accepted_at', 6)->nullable();
            $t->string('failure_code', 64)->nullable();
            $t->index(['institution_id', 'status', 'available_at']);
        });
        Schema::create('collab_transport_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->string('event_id', 64)->charset('ascii')->collation('ascii_bin');
            $t->foreign('event_id')->references('id')->on('collab_events')->cascadeOnDelete();
            $t->string('device_id', 26);
            $t->timestamp('accepted_at', 6);
            $t->unique(['event_id', 'device_id']);
        });
        Schema::create('collab_notification_delivery', function (Blueprint $t): void {
            $t->string('intent_id', 64)->charset('ascii')->collation('ascii_bin')->primary();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->string('status', 32);
            $t->unsignedInteger('accepted_devices')->default(0);
            $t->timestamp('updated_at', 6);
        });
    }

    public function down(): void
    {
        foreach (['collab_notification_delivery', 'collab_transport_receipts', 'collab_outbox', 'collab_events', 'collab_streams'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
