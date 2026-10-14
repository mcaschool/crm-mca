<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migración de DATOS: secuencia de orden estable para el catálogo.
 *
 * Los programas importados compartían display_order (los 77 de MCA tenían 1), así que «Subir» y
 * «Bajar» intercambiaban valores iguales y no cambiaban nada. En cada institución se numeran
 * 1..n los programas no borrados en el MISMO orden que ya mostraba el listado (display_order,
 * name_es, id): nada cambia de sitio, solo deja de haber empates. Solo toca display_order.
 *
 * down(): sin efecto. El valor anterior era un empate sin significado (el orden visible es el
 * mismo antes y después); restaurarlo volvería a bloquear la reordenación.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $institutions = DB::table('programs')->whereNull('deleted_at')->distinct()->pluck('institution_id');

            foreach ($institutions as $institutionId) {
                $rows = DB::table('programs')
                    ->where('institution_id', $institutionId)
                    ->whereNull('deleted_at')
                    ->orderBy('display_order')->orderBy('name_es')->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'display_order']);

                foreach ($rows->values() as $index => $row) {
                    if ((int) $row->display_order !== $index + 1) {
                        DB::table('programs')->where('id', $row->id)->update(['display_order' => $index + 1]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Intencionadamente vacío (ver cabecera).
    }
};
