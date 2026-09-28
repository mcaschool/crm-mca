<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Migración de datos (Bloque 4c): conserva el comportamiento actual del emparejador de Celia
 * asignándole los programas de Microcredenciales (code "MC-…", no borrados) de SU institución.
 * El bot se resuelve por slug "microcredenciales", nunca por id. A ningún otro bot (p. ej.
 * Lola) se le asigna nada. Idempotente (insertOrIgnore sobre unique(bot_id, program_id)).
 *
 * down(): quita SOLO esas asignaciones (bots "microcredenciales" ↔ programas "MC-…"); lo que
 * se haya asignado después a otros bots o programas no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $total = 0;

        foreach (DB::table('bots')->where('slug', 'microcredenciales')->get(['id', 'institution_id']) as $bot) {
            $rows = DB::table('programs')
                ->where('institution_id', $bot->institution_id)
                ->where('code', 'like', 'MC-%')
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($programId): array => [
                    'bot_id' => $bot->id,
                    'program_id' => $programId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            $inserted = $rows === [] ? 0 : DB::table('bot_program')->insertOrIgnore($rows);
            $total += $inserted;
            Log::info('bot_program.assign_mc_to_celia', ['bot_id' => $bot->id, 'institution_id' => $bot->institution_id, 'assigned' => $inserted]);
        }

        Log::info('bot_program.assign_mc_to_celia.total', ['assigned' => $total]);
    }

    public function down(): void
    {
        $botIds = DB::table('bots')->where('slug', 'microcredenciales')->pluck('id');
        $programIds = DB::table('programs')->where('code', 'like', 'MC-%')->pluck('id');

        DB::table('bot_program')
            ->whereIn('bot_id', $botIds)
            ->whereIn('program_id', $programIds)
            ->delete();
    }
};
