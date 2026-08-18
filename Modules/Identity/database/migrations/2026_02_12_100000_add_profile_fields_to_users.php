<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil del asesor humano (usuario del panel): numero de identidad (DATO SENSIBLE,
 * se guarda CIFRADO via cast 'encrypted' del modelo -> por eso es TEXT) y
 * departamento (lista fija). El correo (login) y el rol ya existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('national_id')->nullable()->after('email'); // cifrado en reposo
            $table->string('department', 40)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['national_id', 'department']);
        });
    }
};
