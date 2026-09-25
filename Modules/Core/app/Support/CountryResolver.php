<?php

declare(strict_types=1);

namespace Modules\Core\Support;

/**
 * Normaliza un país recibido de fuentes externas (formularios de n8n) a su código
 * ISO-3166-1 alpha-2. Acepta el código ISO-2 directamente o el nombre del país en
 * español o inglés, sin distinguir mayúsculas ni acentos. Si no se reconoce, devuelve
 * null (el llamador NO debe perder el lead por esto: guarda null y conserva el crudo).
 *
 * Cobertura pensada para el alumnado real (Latinoamérica, EE. UU./Canadá y Europa),
 * ampliable añadiendo entradas al mapa. No deriva nacionalidad: solo residencia/país.
 */
final class CountryResolver
{
    /** Códigos ISO-2 válidos reconocidos (los del mapa de nombres). */
    private const NAME_TO_ISO2 = [
        // Norteamérica
        'estados unidos' => 'US', 'estados unidos de america' => 'US', 'eeuu' => 'US', 'ee uu' => 'US', 'usa' => 'US', 'united states' => 'US', 'united states of america' => 'US',
        'canada' => 'CA', 'canada ' => 'CA',
        'mexico' => 'MX',
        // Centroamérica y Caribe
        'guatemala' => 'GT', 'belice' => 'BZ', 'belize' => 'BZ', 'el salvador' => 'SV', 'honduras' => 'HN',
        'nicaragua' => 'NI', 'costa rica' => 'CR', 'panama' => 'PA',
        'cuba' => 'CU', 'republica dominicana' => 'DO', 'dominican republic' => 'DO', 'puerto rico' => 'PR',
        'haiti' => 'HT', 'jamaica' => 'JM', 'trinidad y tobago' => 'TT',
        // Sudamérica
        'argentina' => 'AR', 'bolivia' => 'BO', 'brasil' => 'BR', 'brazil' => 'BR', 'chile' => 'CL',
        'colombia' => 'CO', 'ecuador' => 'EC', 'paraguay' => 'PY', 'peru' => 'PE', 'uruguay' => 'UY',
        'venezuela' => 'VE', 'guyana' => 'GY', 'surinam' => 'SR', 'suriname' => 'SR',
        // Europa (frecuentes)
        'espana' => 'ES', 'spain' => 'ES', 'portugal' => 'PT', 'francia' => 'FR', 'france' => 'FR',
        'italia' => 'IT', 'italy' => 'IT', 'alemania' => 'DE', 'germany' => 'DE',
        'reino unido' => 'GB', 'united kingdom' => 'GB', 'inglaterra' => 'GB', 'irlanda' => 'IE', 'ireland' => 'IE',
        'paises bajos' => 'NL', 'netherlands' => 'NL', 'holanda' => 'NL', 'belgica' => 'BE', 'belgium' => 'BE',
        'suiza' => 'CH', 'switzerland' => 'CH', 'austria' => 'AT', 'suecia' => 'SE', 'sweden' => 'SE',
        'noruega' => 'NO', 'norway' => 'NO', 'dinamarca' => 'DK', 'denmark' => 'DK', 'finlandia' => 'FI', 'finland' => 'FI',
        'polonia' => 'PL', 'poland' => 'PL', 'grecia' => 'GR', 'greece' => 'GR', 'rumania' => 'RO', 'romania' => 'RO',
        // Otros frecuentes
        'andorra' => 'AD', 'marruecos' => 'MA', 'morocco' => 'MA', 'china' => 'CN', 'india' => 'IN',
        'australia' => 'AU', 'japon' => 'JP', 'japan' => 'JP',
    ];

    /**
     * Devuelve el código ISO-2 (mayúsculas) o null si no se reconoce.
     */
    public static function toIso2(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        // Entrada de dos letras: se trata como código ISO-2 (se normaliza a mayúsculas).
        if (preg_match('/^[A-Za-z]{2}$/', $raw)) {
            return strtoupper($raw);
        }

        // Nombre de país (es/en), sin acentos ni mayúsculas.
        return self::NAME_TO_ISO2[self::normalize($raw)] ?? null;
    }

    /** minúsculas + sin acentos + espacios colapsados. */
    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        ]);

        return (string) preg_replace('/\s+/', ' ', $value);
    }
}
