<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('auditable_type', 120);
            $table->unsignedBigInteger('auditable_id');
            // Cambio con SECRETOS REDACTADOS: registra que cambio, nunca el valor.
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            // Append-only: solo created_at.
            $table->timestamp('created_at')->nullable();

            $table->index(['institution_id', 'auditable_type', 'auditable_id']);
            $table->index(['institution_id', 'action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
