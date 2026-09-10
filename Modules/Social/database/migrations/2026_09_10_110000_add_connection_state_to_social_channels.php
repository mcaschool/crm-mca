<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de conexión del canal (Coexistence-ready). NO va en credentials porque no es un
 * secreto y la UI/las consultas lo necesitan en claro:
 *  - connection_status: pending_setup | connected_cloud_api | connected_coexistence |
 *    offboarded | reconnecting | disconnected | error (null = legado, se asume Cloud API).
 *  - connection_meta: metadata NO sensible del onboarding/sincronización (waba suscrita,
 *    marcas de una-sola-vez de sync de contactos/historial, offboarded_at, progreso de
 *    history…). Jamás tokens, secretos ni authorization codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_channels', function (Blueprint $table) {
            $table->string('connection_status')->nullable()->after('is_active');
            $table->json('connection_meta')->nullable()->after('connection_status');
        });
    }

    public function down(): void
    {
        Schema::table('social_channels', function (Blueprint $table) {
            $table->dropColumn(['connection_status', 'connection_meta']);
        });
    }
};
