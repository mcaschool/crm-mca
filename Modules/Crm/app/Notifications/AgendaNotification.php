<?php

declare(strict_types=1);

namespace Modules\Crm\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Crm\Models\Activity;

/**
 * Notificación interna (canal `database`) de la agenda para el asesor responsable:
 * actividad asignada por otra persona o recordatorio. Solo referencia y datos mínimos
 * (título, hora); nunca enlaces de reunión ni datos de invitados.
 */
final class AgendaNotification extends Notification
{
    public function __construct(
        private readonly string $kind,
        private readonly int $activityId,
        private readonly string $title,
        private readonly string $startsAtIso,
        private readonly string $timezone,
    ) {}

    public static function for(string $kind, Activity $activity): self
    {
        return new self($kind, (int) $activity->getKey(), $activity->title, $activity->starts_at->toIso8601String(), $activity->timezone);
    }

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind, // assigned · reminder
            'activity_id' => $this->activityId,
            'title' => $this->title,
            'starts_at' => $this->startsAtIso,
            'timezone' => $this->timezone,
        ];
    }
}
