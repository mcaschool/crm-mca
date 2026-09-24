<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla canónica de Laravel Notifications (canal `database`). Habilita el sistema de
 * notificaciones internas que ya espera el trait Notifiable de App\Models\User. La usan
 * las alertas de IA (servicio caído / restablecido) dirigidas a los administradores de
 * la institución afectada. NO guarda secretos ni datos personales del usuario final.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
