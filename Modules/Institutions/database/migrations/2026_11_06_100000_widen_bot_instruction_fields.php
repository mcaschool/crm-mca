<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía los campos de texto largo del asesor a MEDIUMTEXT. `instructions`, `restrictions` y
 * `handoff_rules` eran TEXT (máx. 65.535 BYTES): con utf8mb4 (hasta 4 bytes por carácter) eso no
 * garantiza los límites de edición (50.000 / 20.000 caracteres) y podría truncar en silencio.
 * MEDIUMTEXT (16 MB) los admite con holgura. No toca datos existentes ni el resto del esquema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table): void {
            $table->mediumText('instructions')->nullable()->change();
            $table->mediumText('restrictions')->nullable()->change();
            $table->mediumText('handoff_rules')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table): void {
            $table->text('instructions')->nullable()->change();
            $table->text('restrictions')->nullable()->change();
            $table->text('handoff_rules')->nullable()->change();
        });
    }
};
