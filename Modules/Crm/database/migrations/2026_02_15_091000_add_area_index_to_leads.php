<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indice para las consultas por AREA (filtro de la lista de leads y grafica
 * "Leads por area" del dashboard de Marketing). Los conteos por estado/tipo/fecha
 * ya estan cubiertos por indices compuestos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->index(['institution_id', 'area']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex(['institution_id', 'area']);
        });
    }
};
