<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agenda interna del CRM (Fase 1): actividades (llamadas, reuniones, seguimientos, tareas),
 * invitados, reprogramaciones, recordatorios, perfil de reuniones y disponibilidad de cada
 * asesor. Todas las tablas llevan institution_id (aislamiento por institución). Las fechas
 * absolutas se guardan en UTC; la zona horaria de presentación va aparte.
 *
 * La tabla `events` NO se toca: sigue siendo el historial append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_meeting_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('timezone', 64)->default('UTC');
            $table->string('default_medium', 20)->default('google_meet');
            // Enlaces del PARTICIPANTE y números: cifrados (pueden llevar códigos de acceso).
            $table->text('meet_url')->nullable();
            $table->text('zoom_url')->nullable();
            $table->text('whatsapp_number')->nullable();
            $table->text('phone')->nullable();
            $table->string('address', 255)->nullable();
            $table->boolean('is_active')->default(true);
            // Parámetros de disponibilidad (se editan en «Mi disponibilidad»).
            $table->unsignedSmallInteger('default_duration_minutes')->default(30);
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->unsignedInteger('min_notice_minutes')->default(0);
            $table->unsignedSmallInteger('max_horizon_days')->default(90);
            $table->timestamps();

            $table->unique(['institution_id', 'user_id']);
        });

        Schema::create('crm_availability_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = domingo … 6 = sábado (ISO local del asesor)
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['institution_id', 'user_id', 'weekday']);
        });

        Schema::create('crm_availability_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            // unavailable = ausencia / vacaciones · available = horario extra ese día
            $table->string('kind', 20)->default('unavailable');
            $table->time('start_time')->nullable(); // null = todo el día
            $table->time('end_time')->nullable();
            $table->string('reason', 191)->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'user_id', 'date_from', 'date_to'], 'crm_avail_exc_lookup');
        });

        Schema::create('crm_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->string('type', 30);
            $table->string('medium', 20)->default('none');
            $table->dateTime('starts_at'); // UTC
            $table->dateTime('ends_at');   // UTC
            $table->string('timezone', 64);
            $table->string('priority', 10)->default('normal');
            $table->string('status', 20)->default('scheduled');
            $table->text('description')->nullable();
            $table->text('outcome')->nullable();
            $table->string('location', 255)->nullable();
            // Foto del enlace del asesor al crear (cifrada): no cambia si luego cambia el perfil.
            $table->text('meeting_link')->nullable();
            $table->json('reminders')->nullable(); // minutos antes: [1440, 60, 15]
            $table->string('uid', 120)->unique(); // UID estable del .ics
            $table->unsignedInteger('ics_sequence')->default(0);
            $table->unsignedSmallInteger('reschedule_count')->default(0);
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            // Excepción manual (fuera de disponibilidad o en conflicto) y quién la autorizó.
            $table->foreignId('exception_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('exception_at')->nullable();
            $table->string('exception_reason', 255)->nullable();
            // Idempotencia del formulario (doble envío / reintento).
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'idempotency_key']);
            $table->index(['institution_id', 'assigned_to_user_id', 'starts_at'], 'crm_act_advisor_time');
            $table->index(['institution_id', 'status', 'starts_at'], 'crm_act_status_time');
        });

        Schema::create('crm_activity_reschedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('activity_id')->constrained('crm_activities')->cascadeOnDelete();
            $table->dateTime('previous_starts_at');
            $table->dateTime('previous_ends_at');
            $table->dateTime('new_starts_at');
            $table->dateTime('new_ends_at');
            $table->foreignId('by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('crm_activity_guests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('activity_id')->constrained('crm_activities')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('name', 191);
            $table->string('email', 191); // en minúsculas
            $table->string('language', 2)->default('es');
            // pending · sent · failed · removed
            $table->string('status', 20)->default('pending');
            $table->string('last_email_kind', 20)->nullable();
            $table->dateTime('last_emailed_at')->nullable();
            $table->dateTime('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'email']);
        });

        Schema::create('crm_activity_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('activity_id')->constrained('crm_activities')->cascadeOnDelete();
            $table->unsignedSmallInteger('offset_minutes');
            $table->string('recipient_type', 10); // advisor · guest
            $table->unsignedBigInteger('recipient_id');
            $table->string('channel', 10); // mail · internal
            $table->dateTime('due_at'); // UTC
            // pending · sending · sent · failed · skipped
            $table->string('status', 10)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable(); // interno; nunca se muestra al invitado
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'offset_minutes', 'recipient_type', 'recipient_id', 'channel'], 'crm_act_reminder_unique');
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activity_reminders');
        Schema::dropIfExists('crm_activity_guests');
        Schema::dropIfExists('crm_activity_reschedules');
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_availability_exceptions');
        Schema::dropIfExists('crm_availability_rules');
        Schema::dropIfExists('crm_meeting_profiles');
    }
};
