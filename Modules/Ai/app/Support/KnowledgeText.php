<?php

declare(strict_types=1);

namespace Modules\Ai\Support;

use Illuminate\Support\Str;

/**
 * Tratamiento de texto de la búsqueda «precisa» en el conocimiento (sin librerías externas):
 *  - normalización: minúsculas y sin acentos;
 *  - palabras vacías ES/EN (más las de config crm.knowledge.retrieval.stopwords);
 *  - siglas: las cortas que importan (MBA…) no se descartan; las ambiguas de 2 letras (DA, PE)
 *    solo cuentan escritas en MAYÚSCULAS y se expanden a su nombre (config acronyms);
 *  - normalización ligera del español: singular/plural y variantes de una misma palabra
 *    («verifico», «verificar», «verificación» → «verific»).
 * También detecta qué LÍNEAS de programas menciona un texto (config line_terms).
 */
final class KnowledgeText
{
    private const STOPWORDS = [
        // español
        'a', 'al', 'algo', 'alguna', 'alguno', 'algun', 'ante', 'aqui', 'asi', 'aun', 'bien', 'buenas', 'buenos', 'cada', 'como', 'con', 'contra',
        'cual', 'cuales', 'de', 'del', 'desde', 'dia', 'dias', 'donde', 'dos', 'el', 'ella', 'ellas', 'ellos', 'en', 'entre', 'era', 'eres', 'es',
        'esa', 'ese', 'eso', 'esta', 'estan', 'estar', 'este', 'esto', 'estos', 'estas', 'favor', 'fue', 'gracias', 'ha', 'hay', 'hola', 'la', 'las',
        'le', 'les', 'lo', 'los', 'me', 'mi', 'mis', 'mucho', 'muy', 'nos', 'nosotros', 'o', 'para', 'pero', 'poco', 'por', 'porque', 'pueda',
        'puede', 'pueden', 'puedes', 'puedo', 'que', 'quiero', 'quisiera', 'saber', 'se', 'sea', 'ser', 'si', 'sin', 'sobre', 'solo', 'son', 'su',
        'sus', 'tambien', 'te', 'tengo', 'tiene', 'tienen', 'tienes', 'todo', 'todos', 'tu', 'tus', 'un', 'una', 'uno', 'unos', 'unas', 'usted',
        'ustedes', 'vez', 'y', 'ya', 'yo', 'dame', 'darme', 'decir', 'dime', 'decirme', 'hacer', 'hago', 'mas', 'menos', 'otro', 'otra', 'ok',
        'vale', 'pues', 'entonces', 'estoy', 'soy', 'seria', 'podria', 'podrias', 'necesito', 'necesitas', 'quieres', 'algun', 'alguien',
        // inglés
        'about', 'and', 'any', 'are', 'can', 'could', 'does', 'for', 'from', 'have', 'hello', 'hi', 'how', 'into', 'its', 'like', 'many', 'much',
        'need', 'please', 'tell', 'that', 'the', 'their', 'there', 'this', 'what', 'when', 'where', 'which', 'with', 'would', 'you', 'your', 'want',
    ];

    /** Sufijos (de más largo a más corto) de la normalización ligera del español. */
    private const SUFFIXES = [
        'amientos', 'imientos', 'aciones', 'iciones', 'amiento', 'imiento', 'ciones', 'siones', 'mente', 'acion', 'icion', 'ables', 'ibles',
        'ando', 'iendo', 'arme', 'erme', 'irme', 'arlo', 'erlo', 'irlo', 'arla', 'ados', 'idos', 'adas', 'idas', 'aron',
        'ieron', 'amos', 'emos', 'imos', 'cion', 'sion', 'able', 'ible', 'ado', 'ido', 'ada', 'ida', 'ar', 'er', 'ir', 'as',
        'es', 'os', 'an', 'en', 'a', 'e', 'o', 's',
    ];

    /** Raíz mínima: nunca se recorta por debajo de 3 letras. */
    private const MIN_STEM = 3;

    public static function normalize(string $text): string
    {
        return Str::of($text)->lower()->ascii()->toString();
    }

    /**
     * Palabras normalizadas de un texto (sin filtrar).
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', self::normalize($text)) ?: [], fn (string $w): bool => $w !== ''));
    }

    public static function stem(string $word): string
    {
        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($word, $suffix) && strlen($word) - strlen($suffix) >= self::MIN_STEM) {
                $word = substr($word, 0, -strlen($suffix));

                break;
            }
        }
        // Segunda pasada: un infinitivo que queda tras el sufijo («aprender-ás», «financier-os») se
        // recorta igual que el infinitivo suelto («aprend-o», «financi-era»).
        if (preg_match('/(ar|er|ir)$/', $word) === 1 && strlen($word) - 2 >= 4) {
            $word = substr($word, 0, -2);
        }

        return $word;
    }

    /**
     * Raíces de búsqueda de una PREGUNTA: expande siglas, quita palabras vacías y cortas (salvo
     * las siglas relevantes) y normaliza variantes. Sin repetidos.
     *
     * @return list<string>
     */
    public static function queryTerms(string $question): array
    {
        return array_values(array_unique(self::terms(self::expandAcronyms($question))));
    }

    /**
     * Raíces de un TEXTO del conocimiento (con repeticiones: cuentan para la frecuencia).
     *
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        $stop = self::stopwords();
        $short = self::shortTerms();
        $out = [];
        foreach (self::words($text) as $w) {
            if (isset($stop[$w])) {
                continue;
            }
            if (strlen($w) < 4 && ! isset($short[$w])) {
                continue;
            }
            $out[] = isset($short[$w]) ? $w : self::stem($w);
        }

        return $out;
    }

    /**
     * Líneas de programas que el texto nombra EXPLÍCITAMENTE (config line_terms: frases sin acentos;
     * las siglas de 2 letras solo en mayúsculas).
     *
     * @return list<string> slugs de línea
     */
    public static function linesMentioned(string $text): array
    {
        $normalized = ' '.implode(' ', self::words($text)).' ';
        $lines = [];
        foreach ((array) config('crm.knowledge.retrieval.line_terms', []) as $line => $phrases) {
            foreach ((array) $phrases as $phrase) {
                $phrase = (string) $phrase;
                $hit = strlen($phrase) <= 2 && strtoupper($phrase) === $phrase
                    ? preg_match('/\b'.preg_quote($phrase, '/').'\b/u', $text) === 1   // sigla corta: tal cual, en mayúsculas
                    : str_contains($normalized, ' '.implode(' ', self::words($phrase)).' ');
                if ($hit) {
                    $lines[] = (string) $line;
                    break;
                }
            }
        }

        return $lines;
    }

    /**
     * Conceptos de una pregunta para COMPARAR preguntas (correcciones aprobadas): sus raíces de
     * búsqueda con los sinónimos configurados reducidos a su clave («empiezan», «comenzar» →
     * «inicio»; «cuándo», «fechas» → «fecha»). Sin repetidos.
     *
     * @return list<string>
     */
    public static function concepts(string $text): array
    {
        $map = self::synonymMap();
        $skip = self::comparisonNoise();
        $concepts = array_map(fn (string $t): string => $map[$t] ?? $t, array_filter(self::queryTerms($text), fn (string $t): bool => ! isset($skip[$t])));

        return array_values(array_unique($concepts));
    }

    /**
     * Raíces que no cuentan al COMPARAR preguntas: las de corrections.ignore y las que nombran una
     * línea (de la línea se ocupa el tema activo, no el parecido).
     *
     * @return array<string, true>
     */
    private static function comparisonNoise(): array
    {
        $words = (array) config('crm.knowledge.corrections.ignore', []);
        foreach ((array) config('crm.knowledge.retrieval.line_terms', []) as $phrases) {
            foreach ((array) $phrases as $phrase) {
                $words[] = (string) $phrase;
            }
        }
        $noise = [];
        foreach ($words as $word) {
            foreach (self::terms(self::expandAcronyms((string) $word)) as $stem) {
                $noise[$stem] = true;
            }
        }

        return $noise;
    }

    /** @return array<string, string> raíz => clave del grupo de sinónimos */
    private static function synonymMap(): array
    {
        $map = [];
        foreach ((array) config('crm.knowledge.corrections.synonyms', []) as $key => $words) {
            foreach ((array) $words as $word) {
                foreach (self::terms((string) $word) as $stem) {
                    $map[$stem] = (string) $key;
                }
            }
        }

        return $map;
    }

    /** ¿Pregunta qué ES algo o pide información general? («qué es…», «qué son…», «información de…», «what is…») */
    public static function asksDefinition(string $question): bool
    {
        return preg_match('/^\s*(?:hola\s+)?(?:que\s+(?:es|son)\b|en\s+que\s+consiste|what\s+(?:is|are)\b)|\binformacion\s+(?:de|sobre|general)\b/', implode(' ', self::words($question))) === 1;
    }

    /** ¿El título de la sección DEFINE algo? («Qué es…», «Qué son…», «Información general…», «What is…») */
    public static function isDefinitionTitle(string $title): bool
    {
        return preg_match('/^\s*(?:que\s+(?:es|son)\b|informacion\s+general\b|what\s+(?:is|are)\b)/', implode(' ', self::words($title))) === 1;
    }

    /** Añade la expansión de las siglas configuradas que aparecen en la pregunta. */
    private static function expandAcronyms(string $text): string
    {
        $extra = [];
        foreach ((array) config('crm.knowledge.retrieval.acronyms', []) as $acronym => $expansion) {
            $acronym = (string) $acronym;
            // 2 letras (DA, PE): solo en mayúsculas («me da» no es «Diploma Avanzado»); más largas, sin distinguir.
            $pattern = '/\b'.preg_quote($acronym, '/').'\b/u'.(strlen($acronym) > 2 ? 'i' : '');
            if (preg_match($pattern, $text) === 1) {
                $extra[] = (string) $expansion;
            }
        }

        return $extra === [] ? $text : $text.' '.implode(' ', $extra);
    }

    /** @return array<string, true> */
    private static function stopwords(): array
    {
        static $cache = [];
        $extra = (array) config('crm.knowledge.retrieval.stopwords', []);
        $key = md5(implode('|', $extra));

        return $cache[$key] ??= array_fill_keys(array_merge(self::STOPWORDS, array_map(fn ($w) => self::normalize((string) $w), $extra)), true);
    }

    /** @return array<string, true> */
    private static function shortTerms(): array
    {
        return array_fill_keys(array_map(fn ($w) => self::normalize((string) $w), (array) config('crm.knowledge.retrieval.short_terms', [])), true);
    }
}
