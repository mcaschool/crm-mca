<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transferencia de seguimiento del lead: a quien esta asignado (un usuario humano)
 * y/o a que departamento. El rastro completo de cada transferencia queda ademas
 * como evento CRM (lead_transferred). Nunca se borran conversaciones ni leads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('assigned_to_user_id')->nullable()->after('interest_level')
                ->constrained('users')->nullOnDelete();
            $table->string('assigned_to_department', 40)->nullable()->after('assigned_to_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to_user_id');
            $table->dropColumn('assigned_to_department');
        });
    }
};
