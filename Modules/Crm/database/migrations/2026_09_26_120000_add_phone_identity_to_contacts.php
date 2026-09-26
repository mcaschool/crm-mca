<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\PhoneNumber;

/**
 * Identidad de contacto por email O teléfono.
 *
 *  - `email` pasa a NULLABLE. La unicidad (institution_id, email) SE CONSERVA: en
 *    MySQL/MariaDB un índice UNIQUE admite múltiples NULL, así que varios contactos
 *    pueden tener email null sin violarla.
 *  - Se añade `phone_normalized` (canónico E.164) + UNIQUE(institution_id, phone_normalized),
 *    que también admite múltiples NULL (contactos sin teléfono o con teléfono ambiguo).
 *  - `phone` (crudo) NO se toca: sigue siendo el valor de PRESENTACIÓN.
 *  - Backfill CONSERVADOR: solo se normalizan teléfonos INEQUÍVOCOS (formato internacional)
 *    y que no colisionen dentro de la institución; los ambiguos o en conflicto se dejan en
 *    null (no se modifica `phone`, no se fusionan contactos).
 *
 * Se usa el query builder (DB), no Eloquent, para no activar el InstitutionScope global
 * durante la migración. Reversible: down() revierte índice, columna y NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('phone_normalized', 30)->nullable()->after('phone');
            $table->string('email', 190)->nullable()->change();
        });

        $this->backfillNormalizedPhones();

        Schema::table('contacts', function (Blueprint $table) {
            $table->unique(['institution_id', 'phone_normalized'], 'contacts_institution_id_phone_normalized_unique');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_institution_id_phone_normalized_unique');
            $table->dropColumn('phone_normalized');
        });

        // Restaura el NOT NULL original (en un rollback sobre datos con email null fallaría;
        // por eso el rollback se hace sobre esquema/entorno controlado).
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('email', 190)->nullable(false)->change();
        });
    }

    /**
     * Rellena phone_normalized SOLO con valores inequívocos y sin colisión por institución.
     * Los ambiguos/en conflicto quedan null (reportables por conteo, sin tocar `phone`).
     */
    private function backfillNormalizedPhones(): void
    {
        $seen = []; // "institution_id|normalized" => true

        DB::table('contacts')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->select(['id', 'institution_id', 'phone'])
            ->chunkById(500, function ($rows) use (&$seen): void {
                foreach ($rows as $row) {
                    $normalized = PhoneNumber::normalize($row->phone); // sin asumir internacional
                    if ($normalized === null) {
                        continue; // ambiguo/ inválido → se deja null
                    }

                    $key = $row->institution_id.'|'.$normalized;
                    if (isset($seen[$key])) {
                        continue; // colisión dentro de la institución → se deja null
                    }

                    $alreadyTaken = DB::table('contacts')
                        ->where('institution_id', $row->institution_id)
                        ->where('phone_normalized', $normalized)
                        ->exists();
                    if ($alreadyTaken) {
                        continue;
                    }

                    DB::table('contacts')->where('id', $row->id)->update(['phone_normalized' => $normalized]);
                    $seen[$key] = true;
                }
            });
    }
};
