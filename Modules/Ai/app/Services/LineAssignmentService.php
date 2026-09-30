<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use InvalidArgumentException;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Support\KnowledgeTaxonomy;
use Modules\Catalog\Models\Program;
use Modules\Institutions\Models\Bot;

/**
 * «Asignar / Quitar línea completa» (Centro de Conocimiento, pestaña Por agente): en un solo
 * paso, para un agente, todas las fuentes de conocimiento ACTIVAS de la línea
 * (knowledge_sources.category) y todos sus programas ACTIVOS (programs.line).
 *
 * Es una acción PUNTUAL (no hay regla persistente por agente): lo que se añada a la línea
 * después no se asigna solo. Todo pasa por las consultas de los modelos, así que el scope de
 * institución limita a la institución actual.
 */
class LineAssignmentService
{
    /**
     * Asigna la línea completa (las fuentes quedan activas para el agente, incluso si estaban
     * pausadas). Idempotente.
     *
     * @return array{sources: int, programs: int}
     */
    public function assignLine(Bot $bot, string $line): array
    {
        [$sourceIds, $programIds] = $this->activeIdsInLine($line);

        if ($sourceIds !== []) {
            $bot->knowledgeSources()->syncWithoutDetaching(array_fill_keys($sourceIds, ['is_active' => true]));
        }
        if ($programIds !== []) {
            $bot->programs()->syncWithoutDetaching($programIds);
        }

        return ['sources' => count($sourceIds), 'programs' => count($programIds)];
    }

    /**
     * Quita del agente las fuentes activas y los programas activos de la línea (no borra nada
     * de la biblioteca ni del catálogo, ni toca a otros agentes).
     *
     * @return array{sources: int, programs: int}
     */
    public function detachLine(Bot $bot, string $line): array
    {
        [$sourceIds, $programIds] = $this->activeIdsInLine($line);

        return [
            'sources' => $sourceIds === [] ? 0 : $bot->knowledgeSources()->detach($sourceIds),
            'programs' => $programIds === [] ? 0 : $bot->programs()->detach($programIds),
        ];
    }

    /** @return array{0: array<int, int>, 1: array<int, int>} */
    private function activeIdsInLine(string $line): array
    {
        if (! KnowledgeTaxonomy::isLine($line)) {
            throw new InvalidArgumentException(__('Línea no válida: :line.', ['line' => $line]));
        }

        $sources = KnowledgeSource::query()->where('category', $line)->where('status', 'active')
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $programs = Program::query()->where('line', $line)->where('status', 'active')
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return [$sources, $programs];
    }
}
