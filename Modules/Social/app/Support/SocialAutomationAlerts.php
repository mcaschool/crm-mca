<?php

declare(strict_types=1);

namespace Modules\Social\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Ai\Notifications\AiServiceAlertNotification;
use Modules\Social\Models\SocialConversation;
use Throwable;

/**
 * Aviso CRÍTICO a los administradores cuando la atención automática de una conversación se
 * detiene por un fallo no recuperable. Reutiliza el sistema de alertas existente (notificación
 * `database` que muestra la campana de alertas) y lo deduplica por institución + cuenta + motivo
 * (un incidente repetido = una sola alerta en la ventana).
 *
 * El aviso NUNCA lleva el contenido de la conversación, datos del contacto, tokens ni errores
 * del proveedor: solo el canal, la cuenta, el motivo y el id interno de la conversación.
 */
final class SocialAutomationAlerts
{
    public function automationStopped(SocialConversation $conversation, string $reason): void
    {
        try {
            $channel = $conversation->channel;
            $ttl = (int) config('crm.ai_alert_dedup_seconds', 1800);
            if (! Cache::add('social.automation.alert:'.$conversation->institution_id.':'.$conversation->social_channel_id.':'.$reason, 1, $ttl)) {
                return;
            }

            Log::warning('social.automation: atención automática detenida', [
                'conversation_id' => $conversation->getKey(),
                'channel_id' => $conversation->social_channel_id,
                'provider' => $conversation->provider,
                'reason' => $reason,
            ]);

            $admins = User::query()
                ->where('institution_id', $conversation->institution_id)
                ->where(fn ($q) => $q->where('role', 'admin')->orWhere('is_super_admin', true))
                ->get();
            if ($admins->isEmpty()) {
                return;
            }

            Notification::send($admins, new AiServiceAlertNotification([
                'type' => 'social_automation_stopped',
                'title' => __('URGENTE: atención automática detenida en una conversación'),
                'process' => __('Atención automática'),
                'provider' => $channel?->providerLabel() ?? (string) $conversation->provider,
                'model' => (string) ($channel->display_name ?? ''),
                'category' => $reason,
                'category_label' => $conversation->automationReasonLabel(),
                'action' => $conversation->automationAction(),
                'conversation_id' => $conversation->getKey(),
                'occurred_at' => now()->toIso8601String(),
            ]));
        } catch (Throwable $e) {
            // Un fallo al avisar nunca rompe el procesamiento del mensaje.
            Log::warning('social.automation: no se pudo avisar a los administradores', ['error' => class_basename($e)]);
        }
    }
}
