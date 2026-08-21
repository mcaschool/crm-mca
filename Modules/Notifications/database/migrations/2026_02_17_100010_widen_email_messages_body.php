<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El modo código permite correos de DISEÑO (HTML/CSS con tablas y estilos inline),
 * que pueden superar el límite de TEXT (64 KB). Se amplía `body` a MEDIUMTEXT (16 MB)
 * para no truncar el cuerpo guardado. (El cuerpo de las plantillas ya es longText.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->mediumText('body')->change();
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->text('body')->change();
        });
    }
};
