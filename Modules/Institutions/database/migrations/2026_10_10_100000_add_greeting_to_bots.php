<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saludo inicial de la CONVERSACIÓN por asesor (ES/EN): el primer mensaje del asesor al empezar.
 * Vacío = el saludo por defecto (lang celia.greeting / greeting_custom). Distinto de
 * widget_welcome_*, que es la burbuja que se ve ANTES de abrir el chat. Lo actualiza también una
 * respuesta aprobada en «Probar asesor» sobre el saludo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->string('greeting_es', 500)->nullable()->after('widget_button_en');
            $table->string('greeting_en', 500)->nullable()->after('greeting_es');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn(['greeting_es', 'greeting_en']);
        });
    }
};
