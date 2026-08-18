<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renombra la ETIQUETA del departamento "Finanzas" a "Contabilidad y Finanzas"
 * (mismo equipo, solo cambia el nombre). No es un departamento nuevo ni una
 * migracion de estructura: solo actualiza el texto en los registros existentes
 * para que coincida con la lista renombrada (config/crm.php + User::DEPARTMENTS).
 *
 * OJO: NO tocar el area academica "Economia y Finanzas" (categoria de programa);
 * aqui solo se toca el departamento interno del equipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('department', 'Finanzas')->update(['department' => 'Contabilidad y Finanzas']);
        DB::table('leads')->where('assigned_to_department', 'Finanzas')->update(['assigned_to_department' => 'Contabilidad y Finanzas']);
    }

    public function down(): void
    {
        DB::table('users')->where('department', 'Contabilidad y Finanzas')->update(['department' => 'Finanzas']);
        DB::table('leads')->where('assigned_to_department', 'Contabilidad y Finanzas')->update(['assigned_to_department' => 'Finanzas']);
    }
};
