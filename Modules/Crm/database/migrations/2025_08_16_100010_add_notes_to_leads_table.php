<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas internas del lead (Bloque 4): un lugar para que Marketing/Admisiones
 * anoten contexto del prospecto. Migracion nueva (el esquema base ya existe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('interest_level');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
