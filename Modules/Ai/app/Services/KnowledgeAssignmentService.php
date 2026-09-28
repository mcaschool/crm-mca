<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Institutions\Models\Bot;

/**
 * Asignación de fuentes de conocimiento (biblioteca central) a bots, vía el pivote
 * bot_knowledge_source. Es la ÚNICA capa con la lógica de asignación: la pestaña Agentes y
 * la ficha del asesor la usan sin duplicarla. Todo respeta el scope de institución.
 *
 * Categoría: un valor null, '' o 'sin_categoria' se trata como "fuentes sin categoría".
 */
class KnowledgeAssignmentService
{
    public const NO_CATEGORY = 'sin_categoria';

    /** Asigna una fuente a un bot (idempotente), activa. */
    public function assignSource(Bot $bot, KnowledgeSource $source): void
    {
        $bot->knowledgeSources()->syncWithoutDetaching([$source->getKey() => ['is_active' => true]]);
    }

    /**
     * Asigna a un bot TODAS las fuentes de una categoría (idempotente), activas.
     * Devuelve cuántas fuentes se consideraron.
     */
    public function assignCategory(Bot $bot, ?string $category): int
    {
        $ids = $this->inCategory($category)->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        $bot->knowledgeSources()->syncWithoutDetaching(
            $ids->mapWithKeys(fn ($id) => [$id => ['is_active' => true]])->all()
        );

        return $ids->count();
    }

    /**
     * Desasigna de un bot TODAS las fuentes de una categoría (solo de ese bot; las fuentes y
     * sus asignaciones a otros bots no se tocan). Devuelve cuántas se desasignaron.
     */
    public function detachCategory(Bot $bot, ?string $category): int
    {
        $ids = $this->inCategory($category)->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }

        return $bot->knowledgeSources()->detach($ids);
    }

    /** ¿Todas las fuentes de la categoría están asignadas y activas para el bot? */
    public function isCategoryFullyActive(Bot $bot, ?string $category): bool
    {
        $ids = $this->inCategory($category)->pluck('id');
        if ($ids->isEmpty()) {
            return false;
        }

        $activeForBot = $bot->knowledgeSources()
            ->wherePivot('is_active', true)
            ->whereIn('knowledge_sources.id', $ids->all())
            ->count();

        return $activeForBot === $ids->count();
    }

    /** Activa/desactiva una fuente para un bot SIN desasignarla (conserva la relación). */
    public function toggle(Bot $bot, KnowledgeSource $source, bool $active): void
    {
        // syncWithoutDetaching actualiza el pivote si ya existe, o lo crea si no.
        $bot->knowledgeSources()->syncWithoutDetaching([$source->getKey() => ['is_active' => $active]]);
    }

    /** Desasigna por completo una fuente de un bot (elimina SOLO la fila del pivote de ese bot). */
    public function detach(Bot $bot, KnowledgeSource $source): void
    {
        $bot->knowledgeSources()->detach($source->getKey());
    }

    /**
     * Interruptor de una fuente para un bot: si no está asignada, la asigna (activa); si lo
     * está, alterna activa/pausada sin desasignarla.
     */
    public function toggleAssignment(Bot $bot, KnowledgeSource $source): void
    {
        $current = $this->assignmentsFor($bot)[$source->getKey()] ?? null;

        if ($current === null) {
            $this->assignSource($bot, $source);
        } else {
            $this->toggle($bot, $source, ! $current);
        }
    }

    /**
     * Estado de asignación de un bot: knowledge_source_id => activa (true) / pausada (false).
     * Las fuentes no asignadas no aparecen.
     *
     * @return array<int, bool>
     */
    public function assignmentsFor(Bot $bot): array
    {
        $out = [];
        foreach (DB::table('bot_knowledge_source')->where('bot_id', $bot->getKey())->get(['knowledge_source_id', 'is_active']) as $row) {
            $out[(int) $row->knowledge_source_id] = (bool) $row->is_active;
        }

        return $out;
    }

    /**
     * Nombres de los agentes que usan ACTIVAMENTE cada fuente (excluyendo, si se indica, el
     * agente actual). Solo bots de la institución activa (scope global de Bot).
     *
     * @return array<int, array<int, string>> knowledge_source_id => nombres
     */
    public function activeAgentNamesBySource(?Bot $except = null): array
    {
        $bots = Bot::query()->get()->keyBy(fn (Bot $b): int => (int) $b->getKey());
        if ($bots->isEmpty()) {
            return [];
        }

        $rows = DB::table('bot_knowledge_source')
            ->whereIn('bot_id', $bots->keys()->all())
            ->where('is_active', true)
            ->get(['bot_id', 'knowledge_source_id']);

        $out = [];
        foreach ($rows as $row) {
            $botId = (int) $row->bot_id;
            if ($except !== null && $botId === (int) $except->getKey()) {
                continue;
            }
            $bot = $bots->get($botId);
            if ($bot !== null) {
                $out[(int) $row->knowledge_source_id][] = (string) $bot->assistant_name;
            }
        }

        return $out;
    }

    /** @return Builder<KnowledgeSource> */
    private function inCategory(?string $category): Builder
    {
        $query = KnowledgeSource::query();

        return ($category === null || $category === '' || $category === self::NO_CATEGORY)
            ? $query->whereNull('category')
            : $query->where('category', $category);
    }
}
