<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierra la dependencia circular: conversations.current_node_id -> conversation_nodes.
 * La columna se creo con conversations; la FK se anade ahora que existe la tabla nodos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('current_node_id')
                ->references('id')->on('conversation_nodes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['current_node_id']);
        });
    }
};
