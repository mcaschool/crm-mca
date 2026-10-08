<?php

declare(strict_types=1);

namespace Modules\Ai\Support;

/**
 * Filtro POSTERIOR de enlaces de una respuesta de IA: solo sobreviven las URL que aparecen
 * TEXTUALMENTE en el conocimiento recuperado para esa respuesta (ni inventadas ni de otra línea
 * que no se recuperó). Las demás se quitan y la frase queda legible. Sin red: solo texto.
 */
final class LinkGuard
{
    private const URL = '#https?://[^\s<>"\'()\[\]{}]+#iu';

    /**
     * @return array{0: string, 1: list<string>} [respuesta filtrada, URL quitadas]
     */
    public static function keepKnown(string $reply, string $knowledge): array
    {
        $allowed = [];
        if (preg_match_all(self::URL, $knowledge, $m) > 0) {
            foreach ($m[0] as $url) {
                $allowed[self::key($url)] = true;
            }
        }

        $removed = [];
        $filtered = (string) preg_replace_callback(self::URL, function (array $match) use ($allowed, &$removed): string {
            $url = rtrim($match[0], '.,;:!?');
            $trail = substr($match[0], strlen($url));
            if (isset($allowed[self::key($url)])) {
                return $match[0];
            }
            $removed[] = $url;

            return $trail;
        }, $reply);

        if ($removed === []) {
            return [$reply, []];
        }

        // La frase queda legible: sin espacios antes de puntuación ni dos puntos colgando.
        $filtered = (string) preg_replace('/[ \t]+/u', ' ', $filtered);
        $filtered = (string) preg_replace('/\s+([.,;!?])/u', '$1', $filtered);
        $filtered = (string) preg_replace('/\s*[:–—-]\s*(?=[.!?]|$)/mu', '', $filtered);
        $filtered = trim((string) preg_replace('/([.!?])[.!?]+/u', '$1', $filtered));
        // Si el enlace quitado cerraba la frase, la frase se cierra igualmente.
        if ($filtered !== '' && preg_match('/[.!?…)»"]$/u', $filtered) !== 1) {
            $filtered .= '.';
        }

        return [$filtered, $removed];
    }

    /** Misma URL aunque cambie la barra final o el uso de mayúsculas en el dominio. */
    private static function key(string $url): string
    {
        $url = rtrim($url, '.,;:!?');
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $host.rtrim((string) ($parts['path'] ?? ''), '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
