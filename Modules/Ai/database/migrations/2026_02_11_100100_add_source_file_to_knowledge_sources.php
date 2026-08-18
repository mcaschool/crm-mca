<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda el nombre del archivo .md de origen de cada fuente de conocimiento, para
 * poder QUITAR un documento desde el panel (borrar la fila y su archivo, no solo
 * marcar inactivo). Lo rellena KnowledgeSyncService al sincronizar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->string('source_file', 255)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->dropColumn('source_file');
        });
    }
};
