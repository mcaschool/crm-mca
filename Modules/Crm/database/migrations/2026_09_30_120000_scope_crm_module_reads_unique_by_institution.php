<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La marca de "visto" de los badges es POR INSTITUCIÓN (el modelo CrmModuleRead usa
 * BelongsToInstitution), pero la clave única era (user_id, module): un super-admin que
 * cambiaba de institución no veía su fila de la otra institución e intentaba crear una
 * segunda → error 1062. La clave pasa a (institution_id, user_id, module).
 *
 * Se añade un índice simple en user_id para que la FK de users siga teniendo índice propio
 * al retirar la clave antigua (en MySQL la FK la usaba como índice de apoyo).
 *
 * down() restaura (user_id, module); si ya hay filas del mismo usuario y módulo en varias
 * instituciones, falla sin tocar nada (habría que resolver esas filas antes).
 */
return new class extends Migration
{
    private const OLD_UNIQUE = 'crm_module_reads_user_id_module_unique';

    private const NEW_UNIQUE = 'crm_module_reads_institution_user_module_unique';

    private const USER_INDEX = 'crm_module_reads_user_id_index';

    public function up(): void
    {
        Schema::table('crm_module_reads', function (Blueprint $table) {
            $table->unique(['institution_id', 'user_id', 'module'], self::NEW_UNIQUE);
            $table->index('user_id', self::USER_INDEX);
        });

        Schema::table('crm_module_reads', function (Blueprint $table) {
            $table->dropUnique(self::OLD_UNIQUE);
        });
    }

    public function down(): void
    {
        $conflicts = DB::table('crm_module_reads')
            ->select('user_id', 'module')
            ->groupBy('user_id', 'module')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'crm_module_reads: no se puede restaurar la clave única (user_id, module): %d combinaciones usuario/módulo tienen filas en varias instituciones (%s). No se ha cambiado nada.',
                $conflicts->count(),
                $conflicts->take(10)->map(fn ($r): string => $r->user_id.'/'.$r->module)->implode(', '),
            ));
        }

        Schema::table('crm_module_reads', function (Blueprint $table) {
            $table->unique(['user_id', 'module'], self::OLD_UNIQUE);
        });

        Schema::table('crm_module_reads', function (Blueprint $table) {
            $table->dropUnique(self::NEW_UNIQUE);
            $table->dropIndex(self::USER_INDEX);
        });
    }
};
