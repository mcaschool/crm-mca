<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| TeamCalls fase 3A (LiveKit): estado MULTIMEDIA de cada participante, separado de su estado en la
| llamada, y revocación (expulsión) por quien la inicia. Solo añade columnas nulas: no toca datos.
|
|  - media_state: connecting (pidió entrar a la sala) | connected (el servidor de medios confirmó la
|    conexión) | disconnected (el servidor de medios confirmó la salida).
|  - media_session: sesión de medios vigente (sid de LiveKit) para descartar eventos de sesiones viejas.
|  - revoked_at / revoked_by: expulsado; no recibe credenciales nuevas y se le desconecta si reutiliza
|    una credencial todavía vigente.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collab_call_participants', function (Blueprint $table): void {
            $table->string('media_state', 16)->nullable()->after('device_id');
            $table->string('media_session', 64)->nullable()->after('media_state');
            $table->timestamp('media_connected_at')->nullable()->after('media_session');
            $table->timestamp('media_disconnected_at')->nullable()->after('media_connected_at');
            $table->timestamp('revoked_at')->nullable()->after('media_disconnected_at');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('collab_call_participants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['media_state', 'media_session', 'media_connected_at', 'media_disconnected_at', 'revoked_at']);
        });
    }
};
