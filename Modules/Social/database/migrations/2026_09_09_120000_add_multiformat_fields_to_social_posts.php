<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publicador multiformato (Post | Reel | Historia). Campos generales de medio (media_*)
 * conviviendo con los image_* históricos (compatibilidad hacia atrás: los registros y el
 * flujo de POST siguen usando image_*; Reel/Historia usan media_*). image_* pasan a nullable
 * porque un Reel/Historia de video no tiene imagen. NADA se elimina ni se renombra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('content_type', 20)->default('post')->after('created_by');   // post | reel | story
            $table->string('media_type', 20)->default('image')->after('content_type');  // image | video
            $table->string('media_path')->nullable()->after('media_type');
            $table->string('media_public_url', 1024)->nullable()->after('media_path');
            $table->string('media_mime', 100)->nullable()->after('media_public_url');
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('image_path')->nullable()->change();
            $table->string('image_public_url', 1024)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Solo se retiran los campos nuevos; image_* se dejan nullable (revertir el NOT NULL
        // fallaría si ya existen filas de video con image_* en null).
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn(['content_type', 'media_type', 'media_path', 'media_public_url', 'media_mime']);
        });
    }
};
