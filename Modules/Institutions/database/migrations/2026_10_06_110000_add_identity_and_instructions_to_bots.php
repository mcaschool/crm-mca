<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Identidad e instrucciones» por asesor: función, instrucciones, tono, límites, mensaje para
 * información no encontrada y reglas de transferencia a una persona. Se combinan con las reglas
 * institucionales inalterables (AdvisorPromptBuilder); nunca las sustituyen.
 *
 * uses_legacy_prompt: los asesores EXISTENTES (Celia) siguen con el prompt global actual mientras
 * no tengan instrucciones propias (comportamiento idéntico). Un asesor NUEVO nunca lo hereda: no
 * se presenta como Celia ni habla solo de Microcredenciales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->string('role_description', 255)->nullable()->after('assistant_name');
            $table->text('instructions')->nullable()->after('role_description');
            $table->string('tone', 255)->nullable()->after('instructions');
            $table->text('restrictions')->nullable()->after('tone');
            $table->string('not_found_message', 500)->nullable()->after('restrictions');
            $table->text('handoff_rules')->nullable()->after('not_found_message');
            $table->boolean('uses_legacy_prompt')->default(false)->after('handoff_rules');
        });

        DB::table('bots')->update(['uses_legacy_prompt' => true]);
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn(['role_description', 'instructions', 'tone', 'restrictions', 'not_found_message', 'handoff_rules', 'uses_legacy_prompt']);
        });
    }
};
