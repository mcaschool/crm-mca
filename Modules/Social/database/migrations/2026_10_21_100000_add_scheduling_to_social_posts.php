<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publicador: borradores, programación automática, historial y resultado real por red.
 *
 * social_posts
 *  - networks: destinos pedidos (facebook|instagram); los targets se crean al publicar.
 *  - scheduled_at (UTC) + timezone (zona IANA en la que se programó, para mostrarla).
 *  - published_at: momento efectivo en que quedó publicada (primera red con éxito).
 *  - run_id + locked_at: reclamo atómico de una ejecución (evita publicar dos veces).
 *  - attempts, last_error, updated_by, cancelled_at/cancelled_by.
 *  - media_name / media_size: nombre y tamaño originales del archivo (presentación).
 * social_post_targets
 *  - published_at, attempts, last_attempt_at, permalink (enlace real devuelto por Meta),
 *    remote_deleted_at (borrado en la red desde el CRM).
 *
 * Solo añade columnas nulas o con valor por defecto: las publicaciones existentes no cambian
 * (siguen con su estado published|partial|failed|processing|pending).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->json('networks')->nullable()->after('status');
            $table->timestamp('scheduled_at')->nullable()->after('networks');
            $table->string('timezone', 64)->nullable()->after('scheduled_at');
            $table->timestamp('published_at')->nullable()->after('timezone');
            $table->string('run_id', 40)->nullable()->after('published_at');
            $table->timestamp('locked_at')->nullable()->after('run_id');
            $table->unsignedSmallInteger('attempts')->default(0)->after('locked_at');
            $table->text('last_error')->nullable()->after('attempts');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('last_error');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('media_name')->nullable()->after('media_mime');
            $table->unsignedBigInteger('media_size')->nullable()->after('media_name');
            $table->index(['status', 'scheduled_at'], 'social_posts_status_scheduled_index');
            $table->index(['institution_id', 'created_at'], 'social_posts_institution_created_index');
        });

        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('error_message');
            $table->unsignedSmallInteger('attempts')->default(0)->after('published_at');
            $table->timestamp('last_attempt_at')->nullable()->after('attempts');
            $table->string('permalink', 1024)->nullable()->after('last_attempt_at');
            $table->timestamp('remote_deleted_at')->nullable()->after('permalink');
            $table->index(['network', 'status', 'published_at'], 'social_post_targets_network_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->dropIndex('social_post_targets_network_status_index');
            $table->dropColumn(['published_at', 'attempts', 'last_attempt_at', 'permalink', 'remote_deleted_at']);
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropIndex('social_posts_status_scheduled_index');
            $table->dropIndex('social_posts_institution_created_index');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['networks', 'scheduled_at', 'timezone', 'published_at', 'run_id', 'locked_at', 'attempts', 'last_error', 'cancelled_at', 'media_name', 'media_size']);
        });
    }
};
