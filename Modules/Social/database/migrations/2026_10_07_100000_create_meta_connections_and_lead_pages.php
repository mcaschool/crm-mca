<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formularios publicitarios multiempresa, configurables desde el panel de cada empresa.
 *
 * meta_connections: la autorización de Meta («Conectar Meta») de cada empresa: token cifrado,
 *   tipo, permisos concedidos, caducidad y estado. Una por empresa; reconectar la sustituye solo
 *   si la nueva autorización funciona.
 * meta_lead_pages: Páginas que esa conexión puede usar para formularios (token de Página cifrado),
 *   si la empresa la usa, el último resultado de «Comprobar acceso» y si recibe contactos.
 *   Totalmente separadas de social_channels: la conexión de Messenger nunca se toca.
 * meta_lead_forms: pasan a colgar de meta_lead_pages (social_channel_id queda opcional, heredado)
 *   y guardan desde cuándo reciben contactos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('token');                                   // cifrado (cast encrypted)
            $table->string('token_type', 20)->nullable();            // USER | SYSTEM_USER | PAGE…
            $table->string('meta_user_id', 64)->nullable();          // quién respalda la conexión
            $table->json('scopes')->nullable();                      // permisos concedidos al token
            $table->timestamp('expires_at')->nullable();             // null = sin caducidad conocida
            $table->timestamp('data_access_expires_at')->nullable();
            $table->string('status', 20)->default('active');         // active | expiring | expired | invalid
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->unique('institution_id');
        });

        Schema::create('meta_lead_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('meta_connection_id')->constrained('meta_connections')->cascadeOnDelete();
            $table->string('page_id', 64);
            $table->string('name', 255);
            $table->text('page_token');                              // cifrado (cast encrypted)
            $table->json('tasks')->nullable();                       // tareas del usuario en la Página
            $table->boolean('available')->default(true);             // la última conexión la devolvió
            $table->boolean('selected')->default(false);             // la empresa la usa para formularios
            $table->string('access_status', 20)->default('unchecked'); // unchecked | verified | failed
            $table->json('access_result')->nullable();               // veredicto saneado (sin tokens)
            $table->timestamp('access_checked_at')->nullable();
            $table->boolean('receiving_enabled')->default(false);    // APAGADO hasta validar acceso y lectura
            $table->timestamp('last_polled_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'page_id']);
            $table->index('page_id');
        });

        Schema::table('meta_lead_forms', function (Blueprint $table) {
            $table->unsignedBigInteger('social_channel_id')->nullable()->change();
            $table->foreignId('meta_lead_page_id')->nullable()->after('social_channel_id')->constrained('meta_lead_pages')->cascadeOnDelete();
            $table->timestamp('receiving_since')->nullable()->after('is_active');
        });
    }

    /**
     * social_channel_id queda opcional también tras revertir (volver a obligatorio exigiría borrar
     * filas): es compatible con el código anterior, que siempre lo rellena.
     */
    public function down(): void
    {
        Schema::table('meta_lead_forms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meta_lead_page_id');
            $table->dropColumn('receiving_since');
        });
        Schema::dropIfExists('meta_lead_pages');
        Schema::dropIfExists('meta_connections');
    }
};
