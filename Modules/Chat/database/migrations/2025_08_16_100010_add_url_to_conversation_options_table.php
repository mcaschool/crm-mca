<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * URL de una opcion con action=external_link (p. ej. "Ver los programas" /
 * "Ir a inscripciones"). Migracion nueva (el esquema base ya existe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_options', function (Blueprint $table) {
            $table->string('url', 500)->nullable()->after('event_type');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_options', function (Blueprint $table) {
            $table->dropColumn('url');
        });
    }
};
