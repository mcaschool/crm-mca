<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| TeamCalls fase 4A: lo MÍNIMO que exige el contrato de eventos de MCA Colaboración (EventEnvelope):
|  - collab_call_calls.event_sequence: contador persistente por llamada (versión secuencial del agregado).
|  - collab_call_events.sequence / correlation_id / causation_id: versión del agregado de cada hecho y su
|    trazabilidad. Único por (call_id, sequence): no puede haber dos hechos con la misma versión.
| No es una outbox (la entrega es de CollaborationRealtime); solo columnas nulas o con valor por defecto.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collab_call_calls', function (Blueprint $table): void {
            $table->unsignedInteger('event_sequence')->default(0)->after('end_reason');
        });
        Schema::table('collab_call_events', function (Blueprint $table): void {
            $table->unsignedInteger('sequence')->nullable()->after('type');
            $table->string('correlation_id', 64)->nullable()->after('dedupe_key');
            $table->string('causation_id', 64)->nullable()->after('correlation_id');
            $table->unique(['call_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('collab_call_events', function (Blueprint $table): void {
            $table->dropUnique(['call_id', 'sequence']);
            $table->dropColumn(['sequence', 'correlation_id', 'causation_id']);
        });
        Schema::table('collab_call_calls', function (Blueprint $table): void {
            $table->dropColumn('event_sequence');
        });
    }
};
