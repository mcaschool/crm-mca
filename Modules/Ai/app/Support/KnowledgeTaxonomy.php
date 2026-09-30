<?php

declare(strict_types=1);

namespace Modules\Ai\Support;

use Illuminate\Support\Str;

/**
 * Taxonomía FIJA del Centro de Conocimiento (config crm.knowledge): la línea
 * (knowledge_sources.category) y el tipo (knowledge_sources.type). Punto único de lectura
 * para validación y UI. Las filas antiguas con valores fuera de la lista se conservan tal
 * cual (no se fuerzan); solo se valida lo que entra nuevo.
 */
final class KnowledgeTaxonomy
{
    public const TYPE_PROGRAM = 'programa_academico';

    public const TYPE_KNOWLEDGE = 'base_conocimiento';

    /** @return array<string, string> Todas las líneas (slug => etiqueta). */
    public static function lines(): array
    {
        return (array) config('crm.knowledge.lines', []);
    }

    /** @return array<string, string> Líneas con programas del catálogo (sin la institucional). */
    public static function programLines(): array
    {
        return array_diff_key(self::lines(), [self::institutionalLine() => true]);
    }

    /** @return array<string, string> Tipos (slug => etiqueta). */
    public static function types(): array
    {
        return (array) config('crm.knowledge.types', []);
    }

    public static function institutionalLine(): string
    {
        return (string) config('crm.knowledge.institutional_line', 'general_institucional');
    }

    public static function isLine(?string $slug): bool
    {
        return $slug !== null && array_key_exists($slug, self::lines());
    }

    /**
     * Slug de línea a partir de un texto libre («Tipo» del Excel del catálogo): acepta el
     * slug, la etiqueta o su singular, sin distinguir mayúsculas ni tildes
     * («Programa Ejecutivo» → programas_ejecutivos, «Maestría» → maestrias). Null si no
     * corresponde a ninguna línea de la lista fija.
     */
    public static function lineFromLabel(?string $text): ?string
    {
        $wanted = self::normalizeText($text);
        if ($wanted === '') {
            return null;
        }

        foreach (self::lines() as $slug => $label) {
            $label = self::normalizeText($label);
            $candidates = [str_replace('_', ' ', $slug), $label, self::singular($label)];
            if (in_array($wanted, $candidates, true) || self::singular($wanted) === self::singular($label)) {
                return $slug;
            }
        }

        return null;
    }

    private static function normalizeText(?string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::ascii(mb_strtolower((string) $text))));
    }

    /** Singular aproximado palabra a palabra (quita «es»/«s» final): basta para las etiquetas fijas. */
    private static function singular(string $text): string
    {
        return implode(' ', array_map(fn (string $w): string => (string) preg_replace('/(es|s)$/', '', $w), explode(' ', $text)));
    }

    public static function isType(?string $type): bool
    {
        return $type !== null && array_key_exists($type, self::types());
    }

    /** Etiqueta legible de una línea; si no está en la lista fija, el propio valor. */
    public static function lineLabel(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }

        return self::lines()[$slug] ?? $slug;
    }

    public static function typeLabel(?string $type): ?string
    {
        return $type !== null ? (self::types()[$type] ?? null) : null;
    }
}
