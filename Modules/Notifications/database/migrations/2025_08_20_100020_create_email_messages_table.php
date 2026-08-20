<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de correos SALIENTES enviados a una persona (lead/contacto). Se crea en
 * la Fase 1/Paso 2 pero se ESCRIBE en el Paso 3 (enviar desde la ficha). Guarda el
 * remitente usado (con snapshot por si el remitente se elimina luego), asunto,
 * cuerpo, quien del equipo lo envio y el resultado. No contiene secretos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('email_sender_id')->nullable()->constrained('email_senders')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_address', 190);   // snapshot del remitente usado
            $table->string('from_name', 120)->nullable();
            $table->string('to_address', 190);
            $table->string('subject', 200);
            $table->text('body');
            $table->string('status', 20)->default('sent'); // sent / failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'contact_id']);
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
