<?php

declare(strict_types=1);

namespace Modules\Crm\Enums;

/**
 * Estado de una actividad. Reprogramar NO es un estado: la actividad sigue
 * «programada» con las nuevas horas y queda registro en crm_activity_reschedules.
 */
enum ActivityStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => __('Programada'),
            self::Completed => __('Completada'),
            self::Cancelled => __('Cancelada'),
            self::NoShow => __('No asistió'),
        };
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
