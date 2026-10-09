<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atención automática de redes con el asesor inteligente: trazabilidad y control. Solo añade
 * columnas NULLABLE: no enciende ningún canal ni cambia el estado de ninguna conversación.
 *
 * social_channels      quién y cuándo asignó/activó/desactivó el asesor en la cuenta.
 * social_conversations motivo visible del estado de automatización, quién/cuándo lo cambió, y el
 *                      «turno» persistente (advisor_lease_until) que impide que dos workers
 *                      atiendan la misma conversación a la vez (sin depender solo de la caché).
 * social_messages      respuesta de IA: asesor, mensaje entrante que la originó y metadatos
 *                      seguros (proveedor, modelo, tokens, duración, categoría de error).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_channels', function (Blueprint $table) {
            $table->foreignId('advisor_assigned_by')->nullable()->after('advisor_pause_on_human')->constrained('users')->nullOnDelete();
            $table->timestamp('advisor_assigned_at')->nullable()->after('advisor_assigned_by');
        });

        Schema::table('social_conversations', function (Blueprint $table) {
            $table->string('automation_reason', 40)->nullable()->after('automation_state');
            $table->foreignId('automation_changed_by')->nullable()->after('automation_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('automation_changed_at')->nullable()->after('automation_changed_by');
            $table->timestamp('advisor_lease_until')->nullable()->after('automation_changed_at');
        });

        Schema::table('social_messages', function (Blueprint $table) {
            $table->foreignId('ai_bot_id')->nullable()->after('sent_by')->constrained('bots')->nullOnDelete();
            $table->foreignId('in_reply_to_id')->nullable()->after('ai_bot_id')->constrained('social_messages')->nullOnDelete();
            $table->json('ai_meta')->nullable()->after('in_reply_to_id');
        });
    }

    public function down(): void
    {
        Schema::table('social_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('in_reply_to_id');
            $table->dropConstrainedForeignId('ai_bot_id');
            $table->dropColumn('ai_meta');
        });

        Schema::table('social_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('automation_changed_by');
            $table->dropColumn(['automation_reason', 'automation_changed_at', 'advisor_lease_until']);
        });

        Schema::table('social_channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advisor_assigned_by');
            $table->dropColumn('advisor_assigned_at');
        });
    }
};
