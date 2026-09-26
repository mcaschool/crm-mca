<?php

declare(strict_types=1);

namespace Modules\Core\Support;

/**
 * Normalización telefónica CENTRALIZADA y reutilizable a una representación canónica tipo
 * E.164 (`+` seguido de 8–15 dígitos). Es deliberadamente CONSERVADORA:
 *
 *  - Números en formato internacional CLARO se canonizan: prefijo `+` (o `00` → `+`),
 *    se eliminan separadores (espacios, guiones, paréntesis, puntos) y se valida la
 *    longitud E.164 (8–15 dígitos).
 *  - NUNCA inventa un código de país. Un número SIN `+`/`00` es NACIONAL AMBIGUO y
 *    devuelve null (no se interpreta en silencio), salvo que el llamador afirme que el
 *    origen es internacional garantizado (p. ej. el wa_id de WhatsApp, que siempre trae
 *    código de país aunque sin `+`): en ese caso `$assumeInternational` lo canoniza.
 *  - Devuelve null también para basura o longitudes fuera de rango.
 *
 * Solo el valor canónico (no nulo) debe usarse para deduplicar; el teléfono ORIGINAL se
 * conserva aparte como valor de presentación.
 */
final class PhoneNumber
{
    /**
     * @param  bool  $assumeInternational  el origen garantiza formato internacional aun sin `+`
     *                                     (p. ej. WhatsApp wa_id). No se usa para adivinar país.
     */
    public static function normalize(?string $raw, bool $assumeInternational = false): ?string
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }

        $international = false;
        if (str_starts_with($s, '+')) {
            $international = true;
            $digits = self::digits(substr($s, 1));
        } elseif (str_starts_with($s, '00')) {
            // Prefijo internacional de acceso: 00 → +.
            $international = true;
            $digits = self::digits(substr($s, 2));
        } else {
            $digits = self::digits($s);
            $international = $assumeInternational;
        }

        // Sin código de país explícito y sin garantía de origen internacional: ambiguo → null.
        if (! $international) {
            return null;
        }

        $len = strlen($digits);
        if ($len < 8 || $len > 15) {
            return null; // fuera de los límites E.164
        }

        return '+'.$digits;
    }

    /** ¿El valor produce un teléfono canónico válido? */
    public static function isNormalizable(?string $raw, bool $assumeInternational = false): bool
    {
        return self::normalize($raw, $assumeInternational) !== null;
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }
}
