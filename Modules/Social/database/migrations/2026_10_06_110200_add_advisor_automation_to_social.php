<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asesor inteligente en Instagram, Messenger y WhatsApp (APAGADO por defecto en cada canal).
 *
 * social_channels: activación, asesor asignado, espera antes de responder, horario de atención
 * automática (null = siempre), mensaje fuera de horario, transferencia a una persona y pausa
 * cuando responde una persona del equipo.
 *
 * social_conversations.automation_state: bot (asesor atendiendo) | waiting_human (esperando a
 * una persona) | human (en atención humana) | paused (automatización pausada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_channels', function (Blueprint $table) {
            $table->boolean('advisor_enabled')->default(false)->after('is_active');
            $table->foreignId('advisor_bot_id')->nullable()->after('advisor_enabled')->constrained('bots')->nullOnDelete();
            $table->unsignedSmallInteger('advisor_reply_delay')->default(0)->after('advisor_bot_id'); // segundos
            $table->json('advisor_schedule')->nullable()->after('advisor_reply_delay');
            $table->text('advisor_off_hours_message')->nullable()->after('advisor_schedule');
            $table->boolean('advisor_handoff_enabled')->default(true)->after('advisor_off_hours_message');
            $table->text('advisor_handoff_message')->nullable()->after('advisor_handoff_enabled');
            $table->boolean('advisor_pause_on_human')->default(true)->after('advisor_handoff_message');
        });

        Schema::table('social_conversations', function (Blueprint $table) {
            $table->string('automation_state', 20)->default('bot')->after('status');
            $table->timestamp('advisor_off_hours_notified_at')->nullable()->after('automation_state');
        });
    }

    public function down(): void
    {
        Schema::table('social_conversations', function (Blueprint $table) {
            $table->dropColumn(['automation_state', 'advisor_off_hours_notified_at']);
        });

        Schema::table('social_channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advisor_bot_id');
            $table->dropColumn([
                'advisor_enabled', 'advisor_reply_delay', 'advisor_schedule', 'advisor_off_hours_message',
                'advisor_handoff_enabled', 'advisor_handoff_message', 'advisor_pause_on_human',
            ]);
        });
    }
};
