<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_advisor_settings', function (Blueprint $table): void {
            $table->string('mode', 24)->nullable();
            // null preserves existing inheritance; [] explicitly permits no channel.
            $table->json('allowed_channel_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_advisor_settings', function (Blueprint $table): void {
            $table->dropColumn(['mode', 'allowed_channel_ids']);
        });
    }
};
