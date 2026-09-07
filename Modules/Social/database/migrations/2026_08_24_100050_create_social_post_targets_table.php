<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resultado de una publicación en UNA red (facebook | instagram). Cada red se publica de forma
 * independiente (una puede tener éxito y la otra fallar), y guarda su propio estado, el id del
 * post devuelto por Meta, el container_id (solo Instagram, flujo de 2 pasos) y el error si falló.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->string('network')->index();               // facebook | instagram
            $table->foreignId('social_channel_id')->nullable()->constrained('social_channels')->nullOnDelete();
            $table->string('status')->default('pending');      // pending | published | failed
            $table->string('external_post_id')->nullable();    // id del post en Meta
            $table->string('container_id')->nullable();        // solo IG (contenedor del paso 1)
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['social_post_id', 'network'], 'social_post_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_targets');
    }
};
