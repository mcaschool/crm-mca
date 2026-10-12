<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendario · Fase 2: salas institucionales permanentes por asesor (un espacio de Google Meet
 * y una reunión de Zoom «sin hora fija» por asesor), creadas con la conexión institucional de la
 * institución. Las credenciales viven cifradas en `integrations` (tipos google_meet y zoom).
 *
 * Además, cada actividad guarda el anfitrión de Zoom que usa (video_host_key) para respetar la
 * capacidad simultánea por anfitrión en la disponibilidad y las reservas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_video_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // asesor
            $table->string('provider', 20);                 // google_meet · zoom
            $table->string('remote_id', 120);               // spaces/{id} · id numérico de la reunión
            $table->text('join_url');                       // URL de PARTICIPANTE (cifrada); nunca la de inicio
            $table->char('join_url_hash', 64);              // sha256 normalizado (Activity::roomHash)
            $table->string('host_key', 80)->nullable();     // zoom:{id del anfitrión} — capacidad simultánea
            // active · replaced · missing · error
            $table->string('status', 20)->default('active');
            // Meet: assigned · failed · not_attempted — Zoom: advisor_host · institutional_host
            $table->string('host_status', 30)->nullable();
            $table->string('host_note', 255)->nullable();   // detalle legible, sin secretos
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_checked_at')->nullable();
            $table->string('last_error', 255)->nullable();  // interno, saneado
            $table->timestamps();

            $table->index(['institution_id', 'user_id', 'provider', 'status'], 'crm_video_rooms_owner');
            $table->index(['institution_id', 'join_url_hash'], 'crm_video_rooms_hash');
        });

        Schema::table('crm_activities', function (Blueprint $table): void {
            $table->string('video_host_key', 80)->nullable()->after('meeting_room_hash');
            $table->index(['institution_id', 'video_host_key', 'starts_at'], 'crm_act_video_host_time');
        });
    }

    public function down(): void
    {
        Schema::table('crm_activities', function (Blueprint $table): void {
            $table->dropIndex('crm_act_video_host_time');
            $table->dropColumn('video_host_key');
        });
        Schema::dropIfExists('crm_video_rooms');
    }
};
