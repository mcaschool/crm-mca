<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguridad verificada en Zoom de cada sala (sala de espera activa, sin «unirse antes que el
 * anfitrión» y con contraseña): null = aún sin verificar · true = protegida · false = Zoom no la
 * mantiene. Solo con true se permite iniciar la reunión como anfitrión desde el CRM. No toca
 * enlaces ni salas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_video_rooms', function (Blueprint $table): void {
            $table->boolean('secure')->nullable()->after('host_note');
        });
    }

    public function down(): void
    {
        Schema::table('crm_video_rooms', function (Blueprint $table): void {
            $table->dropColumn('secure');
        });
    }
};
