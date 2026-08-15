<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Institucion a la que pertenece el usuario del panel.
            // La FK a institutions se anade en una migracion posterior (la tabla
            // institutions aun no existe en este punto del arranque).
            $table->unsignedBigInteger('institution_id')->nullable()->index();
            $table->string('name');
            $table->string('email')->unique(); // unicidad GLOBAL para el login del panel
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            // Rol del panel (admin/marketing/admissions). Autorizacion por Policies.
            $table->string('role', 20)->default('admin');
            // D1: super-admin que puede ver todas las instituciones.
            $table->boolean('is_super_admin')->default(false);
            // i18n: idioma preferido de la interfaz del panel.
            $table->string('preferred_language', 2)->default('es');
            $table->string('status', 20)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['institution_id', 'status']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
