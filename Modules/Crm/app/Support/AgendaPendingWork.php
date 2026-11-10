<?php

declare(strict_types=1);

namespace Modules\Crm\Support;

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\ActivityReminder;
use Modules\Crm\Models\Booking;

/**
 * Comprobación barata que el planificador evalúa ANTES de lanzar `crm:agenda-reminders` como
 * proceso propio (envía correo, que es I/O externo): si no hay recordatorios vencidos, envíos
 * atascados ni correos de reserva por reintentar, el proceso no arranca. Replica las condiciones
 * de entrada del comando; el comando sigue reclamando cada recordatorio de forma atómica.
 */
final class AgendaPendingWork
{
    public static function reminders(): bool
    {
        return (bool) app(CurrentInstitution::class)->runGlobally(fn (): bool => ActivityReminder::query()
            ->where('status', 'pending')
            ->where('due_at', '<=', now())
            ->exists()
            || ActivityReminder::query()
                ->where('status', 'sending')
                ->where('updated_at', '<', now()->subMinutes(10))
                ->exists()
            || Booking::query()
                ->where(fn ($q) => $q->whereNull('confirmation_sent_at')->orWhereNull('advisor_mailed_at'))
                ->where('mail_attempts', '<', Booking::MAX_MAIL_ATTEMPTS)
                ->where('created_at', '<=', now()->subMinute())
                ->where('created_at', '>=', now()->subDays(2))
                ->whereHas('activity', fn ($q) => $q->where('status', 'scheduled'))
                ->exists());
    }
}
