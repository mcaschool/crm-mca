<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name_es', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('credential_en', 200)->nullable(); // semilla de name_en
            $table->foreignId('category_id')->nullable()->constrained('program_categories')->nullOnDelete();
            $table->string('level', 40)->nullable();
            $table->string('goal', 80)->nullable();
            $table->string('profile', 120)->nullable();
            $table->string('duration_es', 80)->nullable();
            $table->string('duration_en', 80)->nullable();
            $table->string('modality_es', 80)->nullable();
            $table->string('modality_en', 80)->nullable();
            $table->text('short_description_es')->nullable();
            $table->text('short_description_en')->nullable();
            // Enlace a la ficha en la web (fuente de la verdad). Ni precio ni temario aqui.
            $table->string('url', 500);
            $table->string('status', 20)->default('active');
            $table->smallInteger('display_order')->default(0);
            $table->softDeletes(); // D10: no se versiona; borrado en blando
            $table->timestamps();

            $table->unique(['institution_id', 'code']);
            $table->index(['institution_id', 'status', 'category_id']);
            $table->index(['institution_id', 'level']);
            $table->index(['institution_id', 'goal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
