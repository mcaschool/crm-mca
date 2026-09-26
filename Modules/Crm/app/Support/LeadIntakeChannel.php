<?php

declare(strict_types=1);

namespace Modules\Crm\Support;

/**
 * Detección CENTRALIZADA y documentada del origen WhatsApp para la ingesta de leads.
 *
 * Una solicitud se considera de WhatsApp cuando se cumple CUALQUIERA de:
 *   - channel = whatsapp
 *   - source  = whatsapp
 *   - form empieza EXACTAMENTE por el prefijo `whatsapp_` (whatsapp_maestrias, whatsapp_pe,
 *     whatsapp_micromba, whatsapp_diplomas, … — lista ABIERTA, no cerrada).
 *
 * Los valores se normalizan con trim + minúsculas antes de comparar. El prefijo se comprueba
 * desde el INICIO exacto (str_starts_with), nunca como coincidencia parcial en otra posición
 * (p. ej. `solicitud_whatsapp_web` NO activa la regla). WhatsApp entrega el teléfono en
 * formato internacional garantizado (wa_id), por eso este origen habilita assumeInternational
 * en la normalización telefónica y exige first_name + last_name + phone.
 */
final class LeadIntakeChannel
{
    public const WHATSAPP_FORM_PREFIX = 'whatsapp_';

    public static function isWhatsApp(?string $channel, ?string $source, ?string $form): bool
    {
        return self::norm($channel) === 'whatsapp'
            || self::norm($source) === 'whatsapp'
            || str_starts_with(self::norm($form), self::WHATSAPP_FORM_PREFIX);
    }

    private static function norm(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
