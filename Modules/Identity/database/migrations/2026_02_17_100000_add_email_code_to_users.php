<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permiso POR USUARIO para el "modo código" del editor de correo (HTML/CSS con vista
 * previa). No va atado a un rol: el Administrador lo concede usuario por usuario. El
 * Admin siempre lo tiene por su rol (ver User::canUseEmailCodeMode). Por defecto OFF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_email_code')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_email_code');
        });
    }
};
