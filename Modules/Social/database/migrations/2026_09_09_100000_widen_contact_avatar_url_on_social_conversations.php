<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto de perfil del contacto (Messenger/Instagram) llega como URL de CDN FIRMADA de Meta,
 * que supera con holgura los 255 caracteres del VARCHAR por defecto (provocaba
 * SQLSTATE[22001] "Data too long for column 'contact_avatar_url'"). Se amplía a TEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_conversations', function (Blueprint $table) {
            $table->text('contact_avatar_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('social_conversations', function (Blueprint $table) {
            $table->string('contact_avatar_url')->nullable()->change();
        });
    }
};
