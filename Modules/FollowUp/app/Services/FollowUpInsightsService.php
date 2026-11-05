<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use App\Models\User;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Services\AiExecutionContext;
use Modules\Ai\Services\AiProcessResolver;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;

final class FollowUpInsightsService
{
    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $previous
     * @return array<string,mixed>
     */
    public function recommend(User $actor, array $report, array $previous, ?int $botId = null): array
    {
        abort_unless($actor->isActive() && $actor->canManageSettings() && (int) $actor->institution_id === app(CurrentInstitution::class)->idOrFail(), 403);
        $n = $report['counts']['subjects'];
        if ($n < 20 || $report['open_windows'] > 0 || $report['quality'] !== []) {
            return ['status' => 'abstained', 'items' => [], 'message' => 'Sin datos suficientes o maduros: se requieren 20 identidades alcanzadas, ventanas de respuesta cerradas y calidad verificable.'];
        }
        $catalog = [];
        foreach ([
            'review_blocks' => ['blocked', 'Revisar los motivos de bloqueo; conservar todas las restricciones.'],
            'review_errors' => ['failed', 'Revisar errores técnicos antes de preparar más seguimientos.'],
            'reduce_pressure' => ['excluded', 'Revisar solicitudes de baja y considerar reducir la frecuencia mediante decisión administrativa.'],
            'human_review' => ['human', 'Revisar los casos pendientes con el equipo humano.'],
        ] as $id => [$metric, $suggestion]) {
            if ($report['counts'][$metric] > 0) {
                $catalog[$id] = ['id' => $id, 'metric' => $metric, 'value' => $report['counts'][$metric], 'previous' => $previous['counts'][$metric], 'suggestion' => $suggestion];
            }
        }
        if (($report['rates']['response'] ?? 100) < 10) {
            $catalog['review_content'] = ['id' => 'review_content', 'metric' => 'response', 'value' => $report['rates']['response'], 'previous' => $previous['rates']['response'], 'suggestion' => 'Revisar la claridad del contenido y los horarios; no aumentar automáticamente el volumen de mensajes.'];
        }
        $status = 'deterministic';
        if ($botId !== null && $catalog !== []) {
            Bot::query()->findOrFail($botId);
            try {
                $resolved = app(AiProcessResolver::class)->resolve($botId, 'conversation');
                if ($resolved === null) {
                    throw new \RuntimeException;
                }
                // AI can only select among evidence-backed safe suggestions, never invent text/actions.
                $result = app(AiChatClient::class)->chat($resolved['integration'], $resolved['model'], [
                    ['role' => 'system', 'content' => 'Analiza únicamente agregados anónimos. No infieras causalidad ni atributos personales. Devuelve JSON {"ids":[]} seleccionando solo identificadores del catálogo. No propongas acciones adicionales.'],
                    ['role' => 'user', 'content' => json_encode(['period' => [$report['from'], $report['to']], 'volume' => $n, 'catalog' => array_values($catalog)], JSON_THROW_ON_ERROR)],
                ], ['structured' => true, 'max_tokens' => 300, 'timeout' => 30], new AiExecutionContext((int) $actor->institution_id, 'followup_insights', (int) $resolved['integration']->id, (string) $resolved['integration']->provider, $resolved['model'], $botId));
                $ids = json_decode($result->content, true, flags: JSON_THROW_ON_ERROR)['ids'] ?? null;
                if (! is_array($ids) || count(array_filter($ids, 'is_string')) !== count($ids) || array_diff($ids, array_keys($catalog)) !== []) {
                    throw new \RuntimeException;
                }
                $catalog = array_intersect_key($catalog, array_flip($ids));
                $status = 'ai_selected';
            } catch (\Throwable) {
                $status = 'provider_unavailable';
            }
        }
        foreach ($catalog as &$item) {
            $item += ['period' => $report['from'].' — '.$report['to'], 'volume' => $n, 'confidence' => 'Suficiencia descriptiva; no significación estadística', 'limitations' => 'Asociación temporal, sin prueba causal. Dimensiones actuales y posible selección de la cohorte.'];
        }

        return ['status' => $status, 'items' => array_values($catalog), 'message' => $catalog === [] ? 'No hay señales suficientes para recomendar cambios.' : 'Propuestas para revisión humana. No se aplicó ningún cambio.'];
    }
}
