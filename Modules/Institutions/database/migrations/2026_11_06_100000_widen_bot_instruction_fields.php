<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía los campos de texto largo del asesor a MEDIUMTEXT. `instructions`, `restrictions` y
 * `handoff_rules` eran TEXT (máx. 65.535 BYTES): con utf8mb4 (hasta 4 bytes por carácter) eso no
 * garantiza los límites de edición (50.000 / 20.000 caracteres) y podría truncar en silencio.
 * MEDIUMTEXT (16 MB) los admite con holgura. No toca datos existentes ni el resto del esquema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table): void {
            $table->mediumText('instructions')->nullable()->change();
            $table->mediumText('restrictions')->nullable()->change();
            $table->mediumText('handoff_rules')->nullable()->change();
        });
    }

    /**
     * Revierte a TEXT. PROTECCIÓN contra pérdida de datos: TEXT admite 65.535 BYTES; si alguna fila
     * ya guarda en estas columnas más de ese tamaño (posible con MEDIUMTEXT), revertir TRUNCARÍA el
     * contenido en silencio. En ese caso se ABORTA con un mensaje claro en vez de truncar: hay que
     * acortar esos textos antes de poder bajar la migración (o conservar MEDIUMTEXT).
     */
    public function down(): void
    {
        $limit = 65535;   // capacidad de TEXT en bytes
        $offenders = [];
        foreach (['instructions', 'restrictions', 'handoff_rules'] as $column) {
            $maxBytes = (int) DB::table('bots')->max(DB::raw("OCTET_LENGTH(`{$column}`)"));
            if ($maxBytes > $limit) {
                $offenders[] = "{$column} ({$maxBytes} bytes)";
            }
        }

        if ($offenders !== []) {
            throw new \RuntimeException(
                'No se revierte a TEXT: truncaría datos existentes mayores de 65.535 bytes en '
                .implode(', ', $offenders).'. Acorta esos textos (o conserva MEDIUMTEXT) antes de bajar esta migración.'
            );
        }

        Schema::table('bots', function (Blueprint $table): void {
            $table->text('instructions')->nullable()->change();
            $table->text('restrictions')->nullable()->change();
            $table->text('handoff_rules')->nullable()->change();
        });
    }
};
