<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjuntos de un correo saliente (metadatos para el historial: nombre, tipo,
 * tamaño). Los límites y tipos se validan en el SERVIDOR antes de aceptar el envío.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('email_message_id')->constrained('email_messages')->cascadeOnDelete();
            $table->string('filename', 255);
            $table->string('mime', 150);
            $table->unsignedBigInteger('size');
            $table->timestamp('created_at')->nullable();

            $table->index('email_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
    }
};
