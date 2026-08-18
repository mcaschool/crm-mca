<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha de Asesor: foto de perfil (avatar) del bot. El NOMBRE del asesor ya vive
 * en bots.assistant_name; aqui se anade la ruta relativa del avatar en el disco
 * publico (storage/app/public), servida por storage:link. Nullable: si no hay
 * imagen, el widget usa un avatar por defecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->string('avatar_path', 255)->nullable()->after('assistant_name');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
