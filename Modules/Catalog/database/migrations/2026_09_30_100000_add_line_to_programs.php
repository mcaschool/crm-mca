<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Línea de formación del programa (programs.line): el MISMO slug que usa el Centro de
 * Conocimiento (config crm.knowledge.lines), para agrupar conocimiento y programas por línea.
 * Columna simple (sin tabla de líneas), nullable e indexada. No toca category_id (área).
 *
 * Relleno en la misma migración por prefijo de code: MC- → microcredenciales,
 * PE- → programas_ejecutivos, DA- → diplomas_avanzados. Cualquier otro queda NULL y se
 * reporta (log + consola). down() elimina la columna.
 */
return new class extends Migration
{
    /** Prefijo de code => slug de línea. */
    private const PREFIXES = [
        'MC-' => 'microcredenciales',
        'PE-' => 'programas_ejecutivos',
        'DA-' => 'diplomas_avanzados',
    ];

    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->string('line', 60)->nullable()->after('category_id')->index();
        });

        $report = $this->backfill();

        Log::info('programs.line.backfill', $report);
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo '  programs.line: '.json_encode($report['assigned'], JSON_UNESCAPED_UNICODE)
                .' · sin línea: '.count($report['unassigned'])
                .($report['unassigned'] !== [] ? ' ('.implode(', ', $report['unassigned']).')' : '').PHP_EOL;
        }
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropIndex(['line']);
            $table->dropColumn('line');
        });
    }

    /**
     * Asigna la línea por prefijo a los programas que aún no la tienen (todas las
     * instituciones, incluidos los borrados en blando). Idempotente.
     *
     * @return array{assigned: array<string, int>, unassigned: array<int, string>}
     */
    public function backfill(): array
    {
        $assigned = [];
        foreach (self::PREFIXES as $prefix => $line) {
            $assigned[$line] = DB::table('programs')
                ->whereNull('line')
                ->where('code', 'like', $prefix.'%')
                ->update(['line' => $line]);
        }

        $unassigned = DB::table('programs')->whereNull('line')->orderBy('id')
            ->get(['id', 'code'])
            ->map(fn ($p): string => (string) ($p->code ?? '#'.$p->id))
            ->all();

        return ['assigned' => $assigned, 'unassigned' => $unassigned];
    }
};
