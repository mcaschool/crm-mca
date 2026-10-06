<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base común del asesor por canal (AdvisorTurnService):
 *  - conversations.is_test: conversación de PRUEBA interna (canal 'preview'); nunca cuenta como
 *    producción (métricas, leads, eventos).
 *  - conversations.external_id: id de la conversación en el canal de origen (sesión de prueba,
 *    hilo de Instagram/Messenger, número de WhatsApp…): memoria separada por asesor + canal +
 *    conversación externa.
 *  - messages.external_id: id del mensaje en el canal de origen (mid/wamid…): idempotencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('channel');
            $table->string('external_id', 191)->nullable()->after('is_test');
            $table->index(['institution_id', 'is_test']);
            $table->index(['bot_id', 'channel', 'external_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('external_id', 191)->nullable()->after('message_type');
            $table->index(['conversation_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'external_id']);
            $table->dropColumn('external_id');
        });

        // MySQL adopta el índice compuesto (bot_id, channel, external_id) para la clave foránea de
        // bot_id (y descarta el suyo): se restituye un índice simple ANTES de quitar el compuesto.
        Schema::table('conversations', function (Blueprint $table) {
            $table->index('bot_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['institution_id', 'is_test']);
            $table->dropIndex(['bot_id', 'channel', 'external_id']);
            $table->dropColumn(['is_test', 'external_id']);
        });
    }
};
