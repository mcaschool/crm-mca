<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil InCompany (formación corporativa) de un lead. TABLA PLANA (no relacional):
 * los 3 programas recomendados son columnas (code + program_id resuelto), no una tabla
 * aparte. 1:1 con un `lead` del CRM (que aporta contacto, estado del pipeline, etc.).
 * Lo arma n8n y entra por el endpoint público InCompany. Acotada por institución.
 *
 * Los `programa_N_code` son los course_idnumber TAL COMO los envía n8n (se guardan
 * siempre); `programa_N_program_id` es el enlace al catálogo (nullable: si el idnumber
 * aún no está poblado o no matchea, queda null y se muestra el code como referencia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incompany_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();

            // Empresa / contacto
            $table->string('nombre_empresa', 150);
            $table->string('nombre_contacto', 120);
            $table->string('email', 190);
            $table->string('whatsapp', 30)->nullable();

            // Calificación
            $table->string('sector', 100)->nullable();
            $table->string('tamano_empresa', 40)->nullable();   // rango, ej. "11-50"
            $table->string('modalidad', 20)->nullable();         // persona | grupo
            $table->unsignedInteger('cantidad_personas')->default(1);

            // Entregable: ruta de hasta 3 programas (code de n8n + enlace al catálogo)
            $table->string('programa_1_code', 100)->nullable();
            $table->foreignId('programa_1_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('programa_2_code', 100)->nullable();
            $table->foreignId('programa_2_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('programa_3_code', 100)->nullable();
            $table->foreignId('programa_3_program_id')->nullable()->constrained('programs')->nullOnDelete();

            $table->string('area_desarrollo', 500)->nullable();  // diagnóstico de una línea
            $table->string('origen', 40)->default('incompany_web');
            $table->timestamps();

            $table->index(['institution_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incompany_leads');
    }
};
