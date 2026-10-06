<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha del asesor:
 *  - «Presentación del widget» por asesor y por idioma (ES/EN): mensaje de bienvenida (la
 *    burbuja/teaser) y texto del botón (lanzador). NULL = el texto actual del widget, así los
 *    asesores existentes se ven exactamente igual.
 *  - Enlace PRIVADO de prueba: token aleatorio del que solo se guarda el hash SHA-256 (búsqueda)
 *    y una copia cifrada (para volver a copiarlo desde la ficha). Revocable y regenerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->string('widget_welcome_es', 200)->nullable()->after('default_language');
            $table->string('widget_welcome_en', 200)->nullable()->after('widget_welcome_es');
            $table->string('widget_button_es', 40)->nullable()->after('widget_welcome_en');
            $table->string('widget_button_en', 40)->nullable()->after('widget_button_es');
            $table->char('preview_token_hash', 64)->nullable()->unique()->after('public_key');
            $table->text('preview_token')->nullable()->after('preview_token_hash'); // cifrado (cast encrypted)
            $table->timestamp('preview_token_created_at')->nullable()->after('preview_token');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropUnique(['preview_token_hash']);
            $table->dropColumn([
                'widget_welcome_es', 'widget_welcome_en', 'widget_button_es', 'widget_button_en',
                'preview_token_hash', 'preview_token', 'preview_token_created_at',
            ]);
        });
    }
};
