<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por asesor:
 *  - knowledge_retrieval: búsqueda en su conocimiento. «classic» (por defecto: todos siguen igual,
 *    Celia incluida) o «precise» (rareza, variantes, programa nombrado, tema activo, filtro de
 *    enlaces y diagnóstico en la prueba). Se activa desde la ficha del asesor.
 *  - ai_message_limit: respuestas de IA por conversación; NULL = el general (crm.celia.message_limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->string('knowledge_retrieval', 20)->default('classic')->after('uses_legacy_prompt');
            $table->unsignedSmallInteger('ai_message_limit')->nullable()->after('knowledge_retrieval');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn(['knowledge_retrieval', 'ai_message_limit']);
        });
    }
};
