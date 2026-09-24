<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Ai\Enums\AiErrorCategory;
use Modules\Ai\Notifications\AiServiceAlertNotification;
use Throwable;

/**
 * Alerta GLOBAL de estado del servicio de IA. Trabaja con la CATEGORÍA normalizada
 * (nunca con códigos de Alibaba/OpenAI). Nace en la capa global (RecordingAiChatClient),
 * así que cualquier agente/proceso nuevo la hereda sin código.
 *
 *  - failure(): registra Log::critical (técnico) Y notifica a los administradores de la
 *    institución afectada, DEDUPLICADO por institución+proceso+proveedor+modelo+categoría
 *    (100 errores iguales ⇒ 1 sola notificación urgente en la ventana).
 *  - recovery(): si esa misma combinación estaba en incidente y vuelve a responder,
 *    notifica "Servicio de IA restablecido" con la duración aproximada, y rearma la
 *    deduplicación para el próximo incidente.
 *
 * NUNCA incluye API keys, Authorization, prompts, conversación, secretos ni datos
 * personales del usuario final.
 */
final class AiAlertDispatcher
{
    public function failure(AiExecutionContext $ctx, AiProviderException $err): void
    {
        $dedupKey = $this->dedupKey($ctx, $err->category->value);
        $ttl = (int) config('crm.ai_alert_dedup_seconds', 1800);

        // Cache::add es ATÓMICO: solo la PRIMERA ocurrencia dentro de la ventana pasa.
        if (! Cache::add($dedupKey, 1, $ttl)) {
            return;
        }

        $startedAt = now()->toIso8601String();
        Cache::put($this->incidentKey($ctx, $err->category->value), $startedAt, 86400);

        Log::critical('ai.alert: fallo tecnico de IA', array_merge($ctx->toLog(), [
            'category' => $err->category->value,
            'http_status' => $err->httpStatus,
            'provider_code' => $err->providerCode,
            'request_id' => $err->requestId,
        ]));

        $this->notifyAdmins($ctx, [
            'type' => 'ai_service_down',
            'title' => 'URGENTE: Servicio de IA no disponible',
            'process' => $ctx->process,
            'bot_id' => $ctx->botId,
            'agent_id' => $ctx->agentId,
            'provider' => $ctx->provider,
            'model' => $ctx->model,
            'category' => $err->category->value,
            'category_label' => $err->category->label(),
            'occurred_at' => $startedAt,
            'technical' => [
                'integration_id' => $ctx->integrationId,
                'http_status' => $err->httpStatus,
                'provider_code' => $err->providerCode,
                'request_id' => $err->requestId,
            ],
        ]);
    }

    /** La combinación volvió a responder: cierra el incidente si estaba activo. */
    public function recovery(AiExecutionContext $ctx): void
    {
        // Puede haber incidentes de distintas categorías para el mismo destino; se cierran todos.
        foreach (AiErrorCategory::cases() as $case) {
            $category = $case->value;
            $incidentKey = $this->incidentKey($ctx, $category);
            $startedAt = Cache::get($incidentKey);
            if (! is_string($startedAt)) {
                continue;
            }

            Cache::forget($incidentKey);
            Cache::forget($this->dedupKey($ctx, $category)); // rearmar para el próximo incidente

            $minutes = 0;
            try {
                $minutes = (int) Carbon::parse($startedAt)->diffInMinutes(now());
            } catch (Throwable) {
                // ignora fecha inválida
            }

            Log::info('ai.alert: servicio de IA restablecido', array_merge($ctx->toLog(), [
                'category' => $category,
                'incident_minutes' => $minutes,
            ]));

            $this->notifyAdmins($ctx, [
                'type' => 'ai_service_restored',
                'title' => 'Servicio de IA restablecido',
                'process' => $ctx->process,
                'bot_id' => $ctx->botId,
                'agent_id' => $ctx->agentId,
                'provider' => $ctx->provider,
                'model' => $ctx->model,
                'recovered_at' => now()->toIso8601String(),
                'incident_started_at' => $startedAt,
                'incident_duration_minutes' => $minutes,
            ]);
        }
    }

    /**
     * Notifica a los administradores (Admin o super-admin) de la institución afectada.
     * Respeta institution_id: JAMÁS mezcla instituciones. Un fallo de notificación nunca
     * rompe la petición del usuario.
     *
     * @param  array<string,mixed>  $payload
     */
    private function notifyAdmins(AiExecutionContext $ctx, array $payload): void
    {
        try {
            $admins = User::query()
                ->where('institution_id', $ctx->institutionId)
                ->where(fn ($q) => $q->where('role', 'admin')->orWhere('is_super_admin', true))
                ->get();

            if ($admins->isEmpty()) {
                return;
            }

            Notification::send($admins, new AiServiceAlertNotification($payload));
        } catch (Throwable $e) {
            Log::warning('ai.alert: no se pudo notificar a administradores', ['error' => $e->getMessage()]);
        }
    }

    private function dedupKey(AiExecutionContext $ctx, string $category): string
    {
        return 'ai.alert.'.$this->combo($ctx, $category);
    }

    private function incidentKey(AiExecutionContext $ctx, string $category): string
    {
        return 'ai.incident.'.$this->combo($ctx, $category);
    }

    private function combo(AiExecutionContext $ctx, string $category): string
    {
        return hash('sha256', implode('|', [
            $ctx->institutionId, $ctx->process, $ctx->provider, $ctx->model, $category,
        ]));
    }
}
