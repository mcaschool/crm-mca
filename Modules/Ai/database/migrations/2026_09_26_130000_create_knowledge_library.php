<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Centro de Conocimiento: biblioteca CENTRAL de fuentes compartible entre agentes.
 *
 *  - knowledge_sources.bot_id → NULLABLE y su FK pasa de cascadeOnDelete a nullOnDelete
 *    (una fuente compartida no debe morir al borrar un bot). Se conserva la columna por
 *    compatibilidad con la UI actual (Ajuste 2), pero la lógica nueva usa el pivote.
 *  - Unicidad: se sustituye (institution_id, bot_id, code) por (institution_id, code):
 *    el código identifica la fuente dentro de la institución, independientemente del bot.
 *  - Pivote bot_knowledge_source (bot ↔ fuente, con is_active): asignación N:N.
 *  - Migración de datos: cada fuente con bot_id se asigna a ese bot en el pivote
 *    (is_active=true). En producción esto es solo Celia ↔ KB-MC-GENERAL-001.
 *
 * REVERSIBILIDAD (limitación documentada): una vez que una fuente se comparte entre varios
 * bots vía pivote, o existe una fuente de biblioteca sin bot_id (bot_id NULL), el modelo
 * 1:N anterior NO puede representarla. Por eso down() es DEFENSIVO: si hay filas con
 * bot_id NULL se DETIENE antes de tocar el esquema (sin revert parcial) y exige restaurar
 * la copia de seguridad previa o una migración correctiva hacia delante (forward-only).
 * Las asignaciones multi-bot del pivote se pierden en el rollback (el modelo 1:N solo
 * conserva el bot_id original de cada fuente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->dropForeign(['bot_id']);
            $table->dropUnique('knowledge_sources_institution_id_bot_id_code_unique');
        });

        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->unsignedBigInteger('bot_id')->nullable()->change();
        });

        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->unique(['institution_id', 'code'], 'knowledge_sources_institution_id_code_unique');
            // La fuente sobrevive al borrado del bot legado; el pivote gestiona la relación real.
            $table->foreign('bot_id')->references('id')->on('bots')->nullOnDelete();
        });

        Schema::create('bot_knowledge_source', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
            $table->foreignId('knowledge_source_id')->constrained('knowledge_sources')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['bot_id', 'knowledge_source_id']);
        });

        // Migración de datos: cada fuente con bot_id → asignación en el pivote.
        foreach (DB::table('knowledge_sources')->whereNotNull('bot_id')->orderBy('id')->get(['id', 'bot_id']) as $row) {
            DB::table('bot_knowledge_source')->insertOrIgnore([
                'bot_id' => $row->bot_id,
                'knowledge_source_id' => $row->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // GUARDA PREVIA (antes de tocar el esquema): el modelo 1:N exige bot_id NOT NULL. Si
        // hay fuentes de biblioteca sin bot (bot_id NULL), no se puede revertir sin borrar/
        // inventar datos → se aborta sin alterar nada (evita el rollback parcial).
        $orphan = DB::table('knowledge_sources')->whereNull('bot_id')->count();
        if ($orphan > 0) {
            throw new RuntimeException(
                "No se puede revertir: existen {$orphan} fuente(s) de conocimiento sin bot_id ".
                '(biblioteca central compartida). Restaura la copia de seguridad previa al despliegue o '.
                'aplica una migración correctiva hacia delante. No se ha alterado el esquema. '.
                'Nota: las asignaciones multi-bot del pivote no son representables en el modelo 1:N.'
            );
        }

        Schema::dropIfExists('bot_knowledge_source');

        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->dropForeign(['bot_id']);
            $table->dropUnique('knowledge_sources_institution_id_code_unique');
        });

        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->unsignedBigInteger('bot_id')->nullable(false)->change();
        });

        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->unique(['institution_id', 'bot_id', 'code'], 'knowledge_sources_institution_id_bot_id_code_unique');
            $table->foreign('bot_id')->references('id')->on('bots')->cascadeOnDelete();
        });
    }
};
