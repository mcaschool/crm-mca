<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha de Asesor Inteligente: tipo del asesor (ia | human). El bot es la entidad
 * de primera clase que representa al asesor (nombre, avatar, idioma, estado,
 * conocimiento y proceso de IA cuelgan de el). 'ia' es el que opera hoy; 'human'
 * queda como etiqueta/estructura para el futuro bloque de intervencion en vivo.
 * Los bots existentes (Celia) se marcan como 'ia'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->string('type', 20)->default('ia')->after('assistant_name');
        });

        DB::table('bots')->update(['type' => 'ia']);
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
