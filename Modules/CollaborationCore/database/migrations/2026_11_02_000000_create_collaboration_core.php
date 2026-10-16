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
        Schema::table('users', function (Blueprint $t): void {
            $t->string('role', 20)->default('member')->change();
            $t->boolean('is_identity_admin')->default(false);
            $t->boolean('crm_legacy_access')->default(false);
        });
        Schema::table('institutions', fn (Blueprint $t) => $t->boolean('collaboration_enabled')->default(true));
        Schema::create('collab_application_access', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('application', 40);
            $t->boolean('allowed');
            $t->timestamps();
            $t->unique(['institution_id', 'user_id', 'application'], 'collab_access_unique');
        });
        DB::table('users')->whereIn('role', ['admin', 'marketing', 'admissions'])->update(['crm_legacy_access' => true]);
        DB::table('users')->where('role', 'admin')->update(['is_identity_admin' => true]);
        Schema::create('collab_devices', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('platform', 16);
            $t->string('label', 200);
            $t->string('session_hash', 64)->unique();
            $t->string('auth_fingerprint', 64);
            $t->string('authentication_level', 16);
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_activity_at');
            $t->timestamp('expires_at')->index();
            $t->timestamps();
            $t->index(['institution_id', 'user_id']);
        });
        Schema::create('collab_presence', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('manual_status', 16)->default('available');
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('last_activity_at')->nullable();
            $t->timestamp('last_online_at')->nullable();
            $t->timestamps();
            $t->unique(['institution_id', 'user_id']);
        });
        Schema::create('collab_presence_devices', function (Blueprint $t): void {
            $t->ulid('device_id')->primary();
            $t->foreign('device_id')->references('id')->on('collab_devices')->cascadeOnDelete();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('expires_at')->index();
            $t->timestamp('last_activity_at');
        });
        Schema::create('collab_presence_calls', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('call_id', 64)->charset('ascii')->collation('ascii_bin');
            $t->timestamp('expires_at')->index();
            $t->unique(['institution_id', 'user_id', 'call_id'], 'collab_call_presence_unique');
        });
        Schema::create('collab_notification_preferences', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->boolean('enabled')->default(true);
            $t->timestamps();
            $t->unique(['institution_id', 'user_id']);
        });
        Schema::create('collab_notification_intents', function (Blueprint $t): void {
            $t->string('id', 64)->charset('ascii')->collation('ascii_bin')->primary();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->string('deduplication_key', 64)->charset('ascii')->collation('ascii_bin');
            $t->string('request_hash', 64);
            $t->string('type', 64);
            $t->string('resource_id', 64);
            $t->string('source_event_id', 64);
            $t->json('recipients');
            $t->json('device_ids');
            $t->string('status', 24)->default('pending');
            $t->timestamp('expires_at')->index();
            $t->timestamps();
            $t->unique(['institution_id', 'deduplication_key'], 'collab_notification_dedupe');
        });
    }

    public function down(): void
    {
        foreach (['collab_notification_intents', 'collab_notification_preferences', 'collab_presence_calls', 'collab_presence_devices', 'collab_presence', 'collab_devices', 'collab_application_access'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('institutions', fn (Blueprint $t) => $t->dropColumn('collaboration_enabled'));
        // Never promote neutral users on rollback: retain the safe 'member' default.
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_identity_admin', 'crm_legacy_access']));
    }
};
