<?php

declare(strict_types=1);

namespace Modules\Crm\Enums;

/**
 * Tipo de actividad de la agenda.
 */
enum ActivityType: string
{
    case Call = 'call';
    case VideoCall = 'video_call';
    case FollowUp = 'follow_up';
    case PendingEmail = 'pending_email';
    case InPersonMeeting = 'in_person_meeting';
    case Task = 'task';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Call => __('Llamada'),
            self::VideoCall => __('Videollamada'),
            self::FollowUp => __('Seguimiento'),
            self::PendingEmail => __('Correo pendiente'),
            self::InPersonMeeting => __('Reunión presencial'),
            self::Task => __('Tarea'),
            self::Other => __('Otro'),
        };
    }

    /** ¿Es una reunión con participantes (admite invitados y correo)? */
    public function isMeeting(): bool
    {
        return in_array($this, [self::Call, self::VideoCall, self::InPersonMeeting, self::FollowUp, self::Other], true);
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
