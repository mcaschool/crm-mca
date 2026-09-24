<?php

declare(strict_types=1);

namespace Modules\Ai\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Notificación interna (canal `database`) de estado del servicio de IA para los
 * administradores: servicio CAÍDO ('ai_service_down') o RESTABLECIDO
 * ('ai_service_restored'). El payload es administrativo + detalle técnico, ya saneado
 * por AiAlertDispatcher: NUNCA contiene API keys, Authorization, prompts, conversación,
 * secretos ni datos personales del usuario final.
 */
final class AiServiceAlertNotification extends Notification
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(private readonly array $payload) {}

    /**
     * @return array<int,string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
