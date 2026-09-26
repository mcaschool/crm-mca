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
 * durante la migración.
 *
 * REVERSIBILIDAD (importante): una vez que existe AL MENOS UN contacto sin email
 * (identificado solo por teléfono), el rollback deja de ser posible de forma segura,
 * porque el esquema anterior exige email NOT NULL y no se inventan emails ni se borran/
 * fusionan contactos. Por eso down() es DEFENSIVO:
 *   - Si NO hay contactos con email NULL → revierte por completo (índice, columna y NOT NULL).
 *   - Si HAY contactos con email NULL → se DETIENE ANTES de tocar el esquema y lanza una
 *     excepción clara. En ese caso la vuelta atrás operativa exige restaurar la copia de
 *     seguridad previa al despliegue, o aplicar una migración correctiva hacia delante
 *     (forward-only). Nunca se produce un rollback parcial.
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
        // GUARDA PREVIA (antes de tocar el esquema): si existen contactos sin email, el
        // esquema anterior (email NOT NULL) no puede restaurarse sin inventar emails ni
        // borrar/fusionar contactos. Se aborta SIN alterar nada (evita el rollback parcial).
        $withoutEmail = DB::table('contacts')->whereNull('email')->count();
        if ($withoutEmail > 0) {
            throw new RuntimeException(
                "No se puede revertir esta migración: existen {$withoutEmail} contacto(s) sin email ".
                '(identificados solo por teléfono). Restaurar email NOT NULL borraría/inventaría datos. '.
                'Para volver atrás: restaura la copia de seguridad previa al despliegue o aplica una '.
                'migración correctiva hacia delante (forward-only). No se ha alterado el esquema.'
            );
        }

        // Sin contactos sin email: reversión completa y segura.
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_institution_id_phone_normalized_unique');
            $table->dropColumn('phone_normalized');
        });
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
