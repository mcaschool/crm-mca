<?php

declare(strict_types=1);

namespace Modules\Crm\Enums;

/**
 * Medio por el que se realiza una actividad. Sin integraciones externas: Meet y Zoom
 * usan el enlace PERMANENTE de participante del perfil del asesor; WhatsApp genera un
 * enlace wa.me (nunca envía mensajes automáticamente).
 */
enum ActivityMedium: string
{
    case GoogleMeet = 'google_meet';
    case Zoom = 'zoom';
    case WhatsApp = 'whatsapp';
    case Phone = 'phone';
    case InPerson = 'in_person';
    case Other = 'other';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::GoogleMeet => 'Google Meet',
            self::Zoom => 'Zoom',
            self::WhatsApp => 'WhatsApp',
            self::Phone => __('Teléfono'),
            self::InPerson => __('Presencial'),
            self::Other => __('Otro'),
            self::None => __('Ninguno'),
        };
    }

    /** Etiqueta en un idioma concreto (correos al invitado). */
    public function labelIn(string $locale): string
    {
        return match ($this) {
            self::GoogleMeet, self::Zoom, self::WhatsApp => $this->label(),
            self::Phone => $locale === 'en' ? 'Phone' : 'Teléfono',
            self::InPerson => $locale === 'en' ? 'In person' : 'Presencial',
            self::Other => $locale === 'en' ? 'Other' : 'Otro',
            self::None => $locale === 'en' ? 'None' : 'Ninguno',
        };
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }
}
