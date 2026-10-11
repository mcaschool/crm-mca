<?php

declare(strict_types=1);

namespace Modules\Ai\Support;

/**
 * Idioma (es | en) de un texto corto, sin librerías externas: cuenta palabras funcionales muy
 * frecuentes de cada idioma (y los signos propios del español). Devuelve null si no hay señal
 * suficiente o empata: quien llama decide el desempate.
 */
final class TextLanguage
{
    private const ES = ['el', 'la', 'los', 'las', 'de', 'del', 'que', 'en', 'es', 'por', 'para', 'con', 'una', 'un', 'y', 'se', 'su', 'puedes',
        'tu', 'te', 'lo', 'al', 'como', 'si', 'esta', 'son', 'hay', 'cual', 'cuales', 'cuanto', 'cuando', 'donde', 'tiene', 'tienen', 'nuestra',
        'nuestro', 'nuestros', 'estudios', 'programa', 'programas', 'diploma', 'costo', 'pagos', 'puede', 'pero', 'tambien', 'muy', 'sobre'];

    private const EN = ['the', 'of', 'and', 'to', 'in', 'is', 'are', 'for', 'with', 'you', 'your', 'can', 'what', 'which', 'how', 'when', 'where',
        'our', 'this', 'that', 'it', 'be', 'do', 'does', 'have', 'has', 'program', 'programs', 'cost', 'payments', 'will', 'there', 'about'];

    public static function detect(string $text): ?string
    {
        $words = KnowledgeText::words($text);
        if ($words === []) {
            return null;
        }
        $es = count(array_intersect($words, self::ES));
        $en = count(array_intersect($words, self::EN));
        // Signos y letras propios del español (sobre el texto original, antes de normalizar).
        if (preg_match('/[¿¡ñáéíóú]/iu', $text) === 1) {
            $es += 2;
        }
        if ($es === $en) {
            return null;
        }

        return $es > $en ? 'es' : 'en';
    }
}
