<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 5: para poder abrir un correo enviado y verlo TAL COMO se envió (con formato
 * e imágenes) y descargar sus adjuntos, se persiste el archivo. Se unifica en
 * email_attachments: `disposition` distingue adjunto normal de imagen inline;
 * `content_id` enlaza la imagen inline con su cid en el cuerpo; `path` es el archivo
 * guardado (disco privado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_attachments', function (Blueprint $table): void {
            $table->string('disposition', 20)->default('attachment')->after('email_message_id');
            $table->string('content_id', 100)->nullable()->after('disposition');
            $table->string('path', 500)->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('email_attachments', function (Blueprint $table): void {
            $table->dropColumn(['disposition', 'content_id', 'path']);
        });
    }
};
