<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publicación de contenido (Bloque 5): una imagen + descripción que se publica a la vez en
 * la Página de Facebook y en Instagram. El estado agrega el resultado POR RED (ver targets):
 * published (todas ok), partial (unas sí y otras no), failed (todas fallaron). Acotado por
 * institución (regla de esquema del proyecto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('caption')->nullable();
            $table->string('image_path');                    // ruta en el disco público
            $table->string('image_public_url', 1024);        // URL HTTPS pública (Meta la descarga)
            $table->string('status')->default('pending')->index();  // pending|partial|published|failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
