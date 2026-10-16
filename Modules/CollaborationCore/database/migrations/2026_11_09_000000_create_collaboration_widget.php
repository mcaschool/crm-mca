<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collab_widget_apps', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->json('origins');
            $t->boolean('enabled')->default(true);
            $t->string('environment', 32);
            $t->string('auth_policy', 32)->default('central_mfa');
            $t->json('capabilities');
            $t->json('appearance');
            $t->timestamps();
        });
        Schema::create('collab_widget_grants', function (Blueprint $t): void {
            $t->char('hash', 64)->primary();
            $t->string('app_id', 64)->index();
            $t->foreign('app_id')->references('id')->on('collab_widget_apps')->cascadeOnDelete();
            $t->string('origin');
            $t->foreignUlid('device_id')->constrained('collab_devices')->cascadeOnDelete();
            $t->string('challenge', 43);
            $t->timestamp('expires_at')->index();
            $t->timestamp('consumed_at')->nullable();
        });
        Schema::create('collab_widget_sessions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('app_id', 64)->index();
            $t->foreign('app_id')->references('id')->on('collab_widget_apps')->cascadeOnDelete();
            $t->string('origin');
            $t->foreignUlid('device_id')->constrained('collab_devices')->cascadeOnDelete();
            $t->char('token_hash', 64)->unique();
            $t->timestamp('expires_at')->index();
            $t->timestamp('absolute_expires_at')->index();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('collab_widget_preferences', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('resource_id', 64)->default('');
            $t->boolean('visual')->default(true);
            $t->boolean('sound')->default(false);
            $t->timestamp('muted_until')->nullable();
            $t->boolean('muted')->default(false);
            $t->timestamps();
            $t->unique(['institution_id', 'user_id', 'resource_id'], 'widget_preference_owner');
        });
    }

    public function down(): void
    {
        foreach (['preferences', 'sessions', 'grants', 'apps'] as $name) {
            Schema::dropIfExists('collab_widget_'.$name);
        }
    }
};
