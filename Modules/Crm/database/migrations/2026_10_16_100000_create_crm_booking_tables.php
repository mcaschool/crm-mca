<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendario · reservas públicas (Fase 3). Reutiliza actividades, disponibilidad y perfil de
 * reuniones de la Fase 1; solo añade lo indispensable:
 *  - Perfil de reuniones: activación de reservas y alias público del asesor.
 *  - Tipos de reunión del asesor (con su alias público).
 *  - Reservas: la actividad creada por un visitante, su token de gestión (hash) y el estado de
 *    los correos (idempotentes y reintentables).
 *  - Enlaces contextuales generados desde la ficha de un lead (token hash, caducan).
 *  - Plantillas de correo del Calendario por institución e idioma.
 *  - Actividades: huella de la sala (Meet/Zoom) para una política conservadora de concurrencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_meeting_profiles', function (Blueprint $table): void {
            $table->boolean('booking_enabled')->default(false)->after('is_active');
            $table->string('booking_slug', 80)->nullable()->after('booking_enabled');
            $table->unique(['institution_id', 'booking_slug']);
        });

        Schema::table('crm_activities', function (Blueprint $table): void {
            // sha256 del enlace de sala normalizado: permite detectar dos reuniones simultáneas en la
            // misma sala sin descifrar enlaces. Nulo si la actividad no tiene sala.
            $table->string('meeting_room_hash', 64)->nullable()->after('meeting_link');
            $table->index(['institution_id', 'meeting_room_hash', 'starts_at'], 'crm_act_room_time');
        });

        Schema::create('crm_meeting_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->string('medium', 20);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(false);
            $table->string('instructions', 500)->nullable();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['institution_id', 'user_id', 'slug']);
        });

        Schema::create('crm_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('activity_id')->unique()->constrained('crm_activities')->cascadeOnDelete();
            $table->foreignId('meeting_type_id')->nullable()->constrained('crm_meeting_types')->nullOnDelete();
            $table->char('token_hash', 64)->unique(); // sha256 del token de gestión: la búsqueda va por aquí
            $table->text('token')->nullable();        // el mismo token CIFRADO, solo para reenviar el enlace de gestión
            $table->string('idempotency_key', 64);
            $table->string('visitor_name', 191);
            $table->string('visitor_email', 191);
            $table->text('visitor_phone')->nullable(); // cifrado
            $table->string('visitor_timezone', 64);
            $table->string('visitor_language', 2)->default('es');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('booking_link_id')->nullable();
            // Correos posteriores a la reserva: se reclaman de forma atómica (null → fecha) y se reintentan.
            $table->dateTime('confirmation_sent_at')->nullable();
            $table->dateTime('advisor_notified_at')->nullable(); // aviso interno (una sola vez)
            $table->dateTime('advisor_mailed_at')->nullable();   // correo al asesor (reintentable)
            $table->unsignedTinyInteger('mail_attempts')->default(0);
            $table->string('mail_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'idempotency_key']);
        });

        Schema::create('crm_booking_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // asesor del enlace
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->timestamps();
        });

        Schema::create('crm_calendar_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('key', 40);   // booking_confirmation · booking_rescheduled · booking_cancelled · advisor_new_booking
            $table->string('locale', 2); // es · en
            $table->string('subject', 200);
            $table->text('body');        // texto plano con variables {{ ... }} autorizadas
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['institution_id', 'key', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_calendar_templates');
        Schema::dropIfExists('crm_booking_links');
        Schema::dropIfExists('crm_bookings');
        Schema::dropIfExists('crm_meeting_types');
        Schema::table('crm_activities', function (Blueprint $table): void {
            $table->dropIndex('crm_act_room_time');
            $table->dropColumn('meeting_room_hash');
        });
        Schema::table('crm_meeting_profiles', function (Blueprint $table): void {
            $table->dropUnique(['institution_id', 'booking_slug']);
            $table->dropColumn(['booking_enabled', 'booking_slug']);
        });
    }
};
