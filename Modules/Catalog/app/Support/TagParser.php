<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

/**
 * Parsea la lista de etiquetas de un programa y separa las estructurales
 * (nivel-*, meta-*, perfil-*) de las libres (dominante-*, tema-*, ...).
 *
 * CRITICO para el emparejador (Bloque 5): level/goal/profile alimentan el filtro
 * determinista; el resto va a program_tags. Un mapeo incorrecto aqui rompe el
 * emparejador, por eso se prueba a fondo.
 */
final class TagParser
{
    private const LEVEL_PREFIX = 'nivel-';

    private const GOAL_PREFIX = 'meta-';

    private const PROFILE_PREFIX = 'perfil-';

    /**
     * @return array{level: ?string, goal: ?string, profile: ?string, tags: array<int,string>}
     */
    public static function parse(string $raw): array
    {
        $level = null;
        $goal = null;
        $profile = null;
        $tags = [];

        foreach (self::split($raw) as $tag) {
            if ($level === null && str_starts_with($tag, self::LEVEL_PREFIX)) {
                $level = self::strip($tag, self::LEVEL_PREFIX);
            } elseif ($goal === null && str_starts_with($tag, self::GOAL_PREFIX)) {
                $goal = self::strip($tag, self::GOAL_PREFIX);
            } elseif ($profile === null && str_starts_with($tag, self::PROFILE_PREFIX)) {
                $profile = self::strip($tag, self::PROFILE_PREFIX);
            } else {
                // Etiqueta libre (dominante-*, tema-*, u otra). Sin duplicados.
                if (! in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }

        return ['level' => $level, 'goal' => $goal, 'profile' => $profile, 'tags' => $tags];
    }

    /**
     * Divide una celda de etiquetas por comas, punto y coma, saltos de linea o
     * espacios; normaliza a minusculas y descarta vacios.
     *
     * @return array<int,string>
     */
    private static function split(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/u', trim($raw)) ?: [];

        $out = [];
        foreach ($parts as $part) {
            $tag = mb_strtolower(trim($part));
            if ($tag !== '' && ! in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        return $out;
    }

    private static function strip(string $tag, string $prefix): string
    {
        return substr($tag, strlen($prefix));
    }
}
