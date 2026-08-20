<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imágenes inline PERSISTIDAS de una plantilla. El cuerpo referencia cada imagen por
 * su `content_id` (<img data-cid="…">); el archivo se guarda en disco privado. Al
 * cargar la plantilla en el compositor, estas imágenes se rehidratan en el pipeline
 * de embebido por CID existente, de modo que viajan DENTRO del correo (Gmail/Outlook)
 * igual que una imagen subida al vuelo. Acotadas por `institution_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_template_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('email_template_id')->constrained('email_templates')->cascadeOnDelete();
            $table->string('content_id', 64);   // cid referenciado en el cuerpo (<img data-cid>)
            $table->string('mime', 100);
            $table->unsignedInteger('size')->default(0);
            $table->string('path', 255)->nullable();  // ruta en el disco privado (local)
            $table->timestamps();

            $table->index(['institution_id', 'email_template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_images');
    }
};
