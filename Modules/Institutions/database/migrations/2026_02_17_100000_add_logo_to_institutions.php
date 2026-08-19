<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logo institucional (marca del panel). Ruta relativa en el disco `public`; se sirve
 * por el symlink public/storage. Nullable: sin logo se usa el fallback (icono + texto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });
    }
};
