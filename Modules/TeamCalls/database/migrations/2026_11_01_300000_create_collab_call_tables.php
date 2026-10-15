<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TeamCalls · llamadas internas entre colaboradores (MCA Colaboración). Prefijo acordado collab_call_.
 * Independiente de la Agenda (crm_video_rooms, Meet, Zoom): no comparte tablas con ella.
 *
 *  - collab_call_calls: una llamada (1:1 o grupal) con su estado GLOBAL, contexto de origen y
 *    clave de idempotencia por quien la inicia.
 *  - collab_call_participants: estado INDIVIDUAL de cada persona (invitación y participación) y
 *    el dispositivo que atendió.
 *  - collab_call_events: traza mínima de lo ocurrido; dedupe_key evita aplicar dos veces un evento
 *    externo repetido (p. ej. webhooks del servidor de medios).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collab_call_calls', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('initiator_id')->constrained('users')->restrictOnDelete();
            $table->string('media', 8);                       // audio | video
            $table->string('scope', 8);                       // direct | group
            $table->string('context_type', 16)->nullable();   // direct | group | channel (conversación de origen)
            $table->string('context_id', 64)->nullable();
            $table->string('status', 16);
            $table->string('room_name', 64)->unique();        // sala futura del servidor de medios
            $table->string('idempotency_key', 64);
            $table->char('request_hash', 64);                 // huella de destino + medio (misma clave ≠ otra llamada)
            $table->timestamp('ringing_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'initiator_id', 'idempotency_key'], 'collab_call_idempotency_unique');
            $table->index(['institution_id', 'status']);
            $table->index(['institution_id', 'context_type', 'context_id']);
            $table->index(['institution_id', 'created_at']);
        });

        Schema::create('collab_call_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUlid('call_id')->constrained('collab_call_calls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 8);                        // host | member
            $table->string('state', 16);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('ring_expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->string('device_id', 64)->nullable();      // dispositivo que atendió / está en la sala
            $table->timestamps();

            $table->unique(['call_id', 'user_id']);
            $table->index(['institution_id', 'user_id', 'state']);
            $table->index(['state', 'ring_expires_at']);
        });

        Schema::create('collab_call_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUlid('call_id')->constrained('collab_call_calls')->cascadeOnDelete();
            $table->string('type', 48);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('data')->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['call_id', 'dedupe_key']);
            $table->index(['call_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collab_call_events');
        Schema::dropIfExists('collab_call_participants');
        Schema::dropIfExists('collab_call_calls');
    }
};
