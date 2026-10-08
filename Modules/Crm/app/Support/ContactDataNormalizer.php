<?php

declare(strict_types=1);

namespace Modules\Crm\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Support\PhoneNumber;
use Modules\Crm\Exceptions\InvalidContactDataException;
use Normalizer;
use Throwable;

/**
 * Capa ÚNICA de normalización y validación de los datos de un contacto, venga de donde venga
 * (Meta, API de captación, InCompany, Web Chat, alta manual, MCP…). ContactService la aplica
 * siempre antes de escribir, y los canales la usan para alinear sus reglas con el esquema.
 * La base de datos queda como última barrera, no como la validación.
 *
 * Política:
 *  - Texto: Unicode NFC, espacios Unicode → espacio normal, sin caracteres de control ni de
 *    anchura cero, espacios exteriores fuera y los interiores colapsados. Vacío → null.
 *  - Marcadores sintéticos de un proveedor (el contacto de prueba de Meta trae
 *    «<test lead: dummy data for …>» en cada campo) → null: nunca se guardan como dato real.
 *  - Longitudes = las del esquema (MAX). Lo que no cabe se RECHAZA: nunca se trunca (un valor
 *    truncado podría fusionar dos contactos distintos o guardar un dato falso).
 *  - Email: minúsculas y formato RFC. Teléfono: se conserva tal cual llega (presentación, con
 *    su prefijo internacional); solo dígitos, separadores habituales y extensión. La clave de
 *    deduplicación la sigue calculando PhoneNumber::normalize (no inventa país).
 *  - País ISO-3166 alfa-2; idioma es|en; fecha de consentimiento real y no futura.
 *  - Tipos: texto o número entero; cualquier otro tipo se rechaza.
 *
 * Los errores (InvalidContactDataException) nombran el campo y el motivo, nunca el valor.
 */
final class ContactDataNormalizer
{
    /** Longitud máxima (en caracteres) de cada columna de `contacts`. */
    public const MAX = [
        'first_name' => 80,
        'last_name' => 80,
        'email' => 190,
        'phone' => 30,
        'country' => 2,
        'preferred_language' => 2,
        'consent_source' => 60,
    ];

    public const LANGUAGES = ['es', 'en'];

    /** Teléfono de presentación: + opcional, dígitos y separadores habituales; extensión opcional. */
    private const PHONE_PATTERN = '/^\+?[0-9 ().\/-]+(?: ?(?:ext\.?|x|#) ?[0-9]{1,6})?$/i';

    /** Marcador sintético de un proveedor (p. ej. «<test lead: dummy data for phone_number>»). */
    private const PLACEHOLDER_PATTERN = '/^<[^<>]*\b(?:test lead|dummy data)\b[^<>]*>$/iu';

    /**
     * Normaliza y valida los campos de contacto PRESENTES en $data (los ausentes siguen
     * ausentes; las demás claves —consent, unsubscribed, phone_assume_international…— pasan
     * intactas). Devuelve los valores ya limpios.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws InvalidContactDataException
     */
    public static function normalize(array $data): array
    {
        $errors = [];

        foreach (['first_name', 'last_name', 'email', 'phone', 'country', 'preferred_language', 'consent_source'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            try {
                $data[$field] = self::field($field, $data[$field]);
            } catch (InvalidContactDataException $e) {
                $errors += $e->errors;
            }
        }

        if (array_key_exists('consent_at', $data)) {
            try {
                $data['consent_at'] = self::consentAt($data['consent_at']);
            } catch (InvalidContactDataException $e) {
                $errors += $e->errors;
            }
        }

        if ($errors !== []) {
            throw new InvalidContactDataException($errors);
        }

        return $data;
    }

    /**
     * Para escrituras DIRECTAS del modelo (herramientas genéricas como el MCP), que no pasan
     * por ContactService: misma validación y, además, el teléfono normalizado se deriva del
     * teléfono (nunca se escribe a mano) y los campos obligatorios no pueden vaciarse.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     *
     * @throws InvalidContactDataException
     */
    public static function forDirectWrite(array $attributes): array
    {
        if (array_key_exists('phone_normalized', $attributes)) {
            throw new InvalidContactDataException(['phone_normalized' => __('Se calcula a partir del teléfono; no se escribe directamente.')]);
        }

        $attributes = self::normalize($attributes);

        $errors = [];
        foreach (['first_name', 'preferred_language'] as $required) {
            if (array_key_exists($required, $attributes) && $attributes[$required] === null) {
                $errors[$required] = __('Es obligatorio.');
            }
        }
        if ($errors !== []) {
            throw new InvalidContactDataException($errors);
        }

        if (array_key_exists('phone', $attributes)) {
            $attributes['phone_normalized'] = PhoneNumber::normalize($attributes['phone']);
        }

        return $attributes;
    }

    /** ¿Es un marcador sintético de un proveedor y no un dato real? */
    public static function isProviderPlaceholder(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PLACEHOLDER_PATTERN, trim($value)) === 1;
    }

    /**
     * Limpieza de texto común (sin reglas de campo). Null si queda vacío o no es texto válido.
     */
    public static function cleanText(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
        }
        $value = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $value);           // espacios Unicode → espacio
        $value = (string) preg_replace('/[\p{Cc}\x{200B}\x{2060}\x{FEFF}]/u', '', $value); // control y anchura cero
        $value = trim((string) preg_replace('/ {2,}/', ' ', $value));

        return $value !== '' ? $value : null;
    }

    /**
     * @throws InvalidContactDataException
     */
    private static function field(string $field, mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (! is_string($raw) && ! is_int($raw)) {
            throw new InvalidContactDataException([$field => __('Debe ser texto.')]);
        }
        if (is_string($raw) && ! mb_check_encoding($raw, 'UTF-8')) {
            throw new InvalidContactDataException([$field => __('Contiene caracteres no válidos.')]);
        }

        $value = self::cleanText($raw);
        if ($value === null || self::isProviderPlaceholder($value)) {
            return null; // vacío o dato sintético de un proveedor: no es un dato real
        }

        $value = match ($field) {
            'email' => mb_strtolower($value),
            'country' => mb_strtoupper($value),
            'preferred_language' => mb_strtolower($value),
            default => $value,
        };

        if (mb_strlen($value) > self::MAX[$field]) {
            throw new InvalidContactDataException([$field => __('Supera el máximo de :max caracteres.', ['max' => self::MAX[$field]])]);
        }

        $valid = match ($field) {
            'email' => Validator::make(['v' => $value], ['v' => 'email:rfc'])->passes(),
            'phone' => preg_match(self::PHONE_PATTERN, $value) === 1 && preg_match('/[0-9]/', $value) === 1,
            'country' => preg_match('/^[A-Z]{2}$/', $value) === 1,
            'preferred_language' => in_array($value, self::LANGUAGES, true),
            default => true,
        };
        if (! $valid) {
            throw new InvalidContactDataException([$field => match ($field) {
                'email' => __('No es un correo válido.'),
                'phone' => __('No es un teléfono válido (solo dígitos, +, espacios, guiones, puntos, paréntesis y extensión).'),
                'country' => __('Debe ser un código de país ISO de 2 letras.'),
                default => __('Debe ser «es» o «en».'),
            }]);
        }

        return $value;
    }

    /**
     * Fecha REAL del consentimiento: válida, no futura y dentro del rango de un TIMESTAMP.
     *
     * @throws InvalidContactDataException
     */
    private static function consentAt(mixed $raw): ?Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $at = $raw instanceof DateTimeInterface ? Carbon::instance($raw) : (is_string($raw) ? Carbon::parse($raw) : null);
        } catch (Throwable) {
            $at = null;
        }
        if ($at === null || $at->year < 1971 || $at->greaterThan(now()->addDay())) {
            throw new InvalidContactDataException(['consent_at' => __('No es una fecha de consentimiento válida.')]);
        }

        return $at;
    }
}
