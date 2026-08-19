<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-contacto de un lead conocido: cuando un contacto que ya existe vuelve a
 * iniciar una conversacion por el widget (tras la ventana de reanudacion de
 * sesion), se sella `last_recontacted_at`. La lista de Leads muestra una senal de
 * actividad nueva no vista mientras `recontacted_seen_at` sea anterior (o nulo);
 * abrir la ficha del lead la sella como vista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->timestamp('last_recontacted_at')->nullable()->after('unsubscribed_at');
            $table->timestamp('recontacted_seen_at')->nullable()->after('last_recontacted_at');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn(['last_recontacted_at', 'recontacted_seen_at']);
        });
    }
};
