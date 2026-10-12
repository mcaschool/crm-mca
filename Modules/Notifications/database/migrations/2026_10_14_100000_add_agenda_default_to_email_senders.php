<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remitente EXPLÍCITO de los correos de la Agenda (invitaciones, actualizaciones,
 * cancelaciones y recordatorios), uno por institución. La marca vive en el propio
 * remitente, así que nunca puede apuntar a otra institución. Sin marca, la Agenda solo
 * usa el remitente si es el ÚNICO activo de la institución.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_senders', function (Blueprint $table): void {
            $table->boolean('agenda_default')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('email_senders', function (Blueprint $table): void {
            $table->dropColumn('agenda_default');
        });
    }
};
