<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotencia PERSISTENTE del asesor: cada mensaje externo (institución + canal + id del
 * mensaje en el canal) se reclama una sola vez. El índice único es la garantía definitiva frente
 * a reintentos y entregas simultáneas; el bloqueo de caché queda como protección adicional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_message_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('channel', 20);
            $table->string('external_message_id', 191);
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('reply_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('status', 20)->default('processing'); // processing | done | skipped (omitido por el adaptador)
            $table->timestamps();

            $table->unique(['institution_id', 'channel', 'external_message_id'], 'advisor_receipts_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_message_receipts');
    }
};
