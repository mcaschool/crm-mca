<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80);
            $table->string('assistant_name', 60);
            $table->string('landing_url', 255)->nullable();
            // Llave opaca embebida en el <script> del widget. El servidor deduce
            // la institucion desde aqui; el cliente nunca envia institution_id.
            $table->char('public_key', 32)->unique();
            // Lista blanca de origenes para CORS del widget.
            $table->json('allowed_origins')->nullable();
            $table->string('default_language', 2)->default('es');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['institution_id', 'slug']);
            $table->index(['institution_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bots');
    }
};
