<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tamaño (alto en px) del logo institucional en el sidebar. Ajustable por el Admin;
 * rango sano acotado en la UI. Por defecto 44px.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('logo_size')->default(44)->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->dropColumn('logo_size');
        });
    }
};
