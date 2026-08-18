<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\ProgramInterest;
use Modules\Institutions\Models\Bot;
use RuntimeException;

/**
 * Borrado seguro de un Asesor (bot). El esquema ya protege el negocio:
 * conversations/leads/program_interests son restrictOnDelete, events es
 * nullOnDelete (se conservan), y solo la config propia (knowledge_sources,
 * conversation_nodes/options, ai_process_configs) es cascadeOnDelete.
 *
 * Esta guarda de aplicacion bloquea ANTES, con mensajes claros:
 *  1. No se elimina un asesor ACTIVO (el del widget): hay que desactivarlo primero.
 *  2. No se elimina un asesor con HISTORICO (conversaciones/leads/intereses):
 *     desactivarlo conserva el negocio.
 * Si esta inactivo y sin historico -> borrado permanente + archivos.
 */
class AdvisorDeletionService
{
    /**
     * Motivo por el que NO se puede eliminar (texto listo para mostrar), o null si
     * es eliminable.
     */
    public function blockReason(Bot $bot): ?string
    {
        if ($bot->status === 'active') {
            return 'Está activo (en uso por el widget). Desactívalo primero para poder eliminarlo.';
        }

        $conversations = Conversation::query()->where('bot_id', $bot->getKey())->count();
        $leads = Lead::query()->where('bot_id', $bot->getKey())->count();
        $interests = ProgramInterest::query()->where('bot_id', $bot->getKey())->count();

        if ($conversations > 0 || $leads > 0 || $interests > 0) {
            return "Tiene datos de negocio asociados ({$conversations} conversaciones, {$leads} leads). "
                .'No se puede eliminar para no perder el histórico: mantenlo desactivado.';
        }

        return null;
    }

    public function canDelete(Bot $bot): bool
    {
        return $this->blockReason($bot) === null;
    }

    /**
     * Elimina permanentemente el asesor y su configuracion propia + archivos. Re-
     * valida la guarda; lanza si esta bloqueado (defensa en profundidad).
     */
    public function delete(Bot $bot): void
    {
        $reason = $this->blockReason($bot);
        if ($reason !== null) {
            throw new RuntimeException('No se puede eliminar este asesor: '.$reason);
        }

        DB::transaction(function () use ($bot): void {
            // Archivos (la BD borra las FILAS en cascada; los archivos hay que borrarlos).
            Storage::disk('knowledge')->deleteDirectory($bot->advisorFolder());
            Storage::disk('public')->deleteDirectory('advisors/'.$bot->advisorFolder());

            // Cascada BD: knowledge_sources, conversation_nodes/options, ai_process_configs.
            // events -> bot_id nulo (se conservan). conversations/leads no existen (bloqueado).
            $bot->delete();
        });
    }
}
