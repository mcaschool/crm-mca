<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil del contacto (Messenger/Instagram): nombre y apellidos por separado cuando Meta los da y
 * seguimiento de la última consulta (estado, intentos y código de error de Meta, nunca el token)
 * para completar los datos que falten sin reconsultar a Meta en cada mensaje.
 *
 * profile_status: complete · partial (falta nombre o foto) · unavailable (Meta no lo permite) ·
 * error (fallo transitorio) · null (nunca consultado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_conversations', function (Blueprint $table): void {
            $table->string('contact_first_name', 120)->nullable()->after('contact_name');
            $table->string('contact_last_name', 120)->nullable()->after('contact_first_name');
            $table->string('profile_status', 16)->nullable()->after('contact_avatar_url');
            $table->timestamp('profile_checked_at')->nullable()->after('profile_status');
            $table->unsignedSmallInteger('profile_attempts')->default(0)->after('profile_checked_at');
            $table->string('profile_error', 60)->nullable()->after('profile_attempts');
            $table->index(['provider', 'profile_status', 'profile_checked_at'], 'social_conv_profile_idx');
        });
    }

    public function down(): void
    {
        Schema::table('social_conversations', function (Blueprint $table): void {
            $table->dropIndex('social_conv_profile_idx');
            $table->dropColumn(['contact_first_name', 'contact_last_name', 'profile_status', 'profile_checked_at', 'profile_attempts', 'profile_error']);
        });
    }
};
