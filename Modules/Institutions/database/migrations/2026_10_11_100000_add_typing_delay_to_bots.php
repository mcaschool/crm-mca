<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Está escribiendo…» por asesor: espera MÍNIMA (segundos, 0–8) entre el mensaje del usuario y la
 * respuesta. En el widget y en «Probar asesor» la hace el navegador (si la IA tarda más, la
 * respuesta sale en cuanto llega); en los canales sociales, el retraso del job en la cola. 0 = sin
 * espera mínima. Por defecto 3 para todos los asesores, actuales y futuros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->unsignedTinyInteger('typing_delay')->default(3)->after('greeting_en');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('typing_delay');
        });
    }
};
