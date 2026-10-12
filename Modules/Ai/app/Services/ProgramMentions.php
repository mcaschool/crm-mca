<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Modules\Ai\Support\KnowledgeTaxonomy;
use Modules\Ai\Support\KnowledgeText;

/**
 * Qué FICHA de programa («programa_academico») nombra un texto, entre las fuentes de un asesor.
 * Un solo algoritmo de candidatos (palabras DISTINTIVAS del título de cada ficha, ponderadas por su
 * rareza entre las fichas), compartido por las dos búsquedas:
 *
 *  - named(): selección de la búsqueda PRECISA (sin cambios: era PreciseKnowledgeRanker::
 *    namedPrograms) → las de máxima coincidencia reciben un bonus.
 *  - scope(): filtro de la búsqueda CLÁSICA → como mucho UN programa admitido:
 *      · código exacto de la ficha (MC-001) o nombre completo / parte distintiva → ese programa;
 *      · varios posibles (palabra genérica de varios nombres, dos programas) → ninguno (ambiguo);
 *      · nada en la pregunta → si es una continuación breve («¿cuánto dura?»), el programa del
 *        contexto reciente; si es general («…las microcredenciales en general»), ninguno.
 *
 * Puro: recibe las fuentes (ya acotadas al asesor y a la institución) y los textos; no toca la BD.
 */
final class ProgramMentions
{
    /** Palabras que marcan una pregunta GENERAL: nunca hereda el programa del contexto. */
    private const GENERAL_WORDS = ['general', 'generales', 'todas', 'todos', 'cualquier', 'cualquiera'];

    /** Una continuación breve tiene como mucho estas raíces de búsqueda («¿y cuánto dura?»). */
    private const BRIEF_TERMS = 4;

    /** Otro programa también nombrado de forma sustancial (cobertura de su nombre) → ambiguo. */
    private const SECOND_PROGRAM_COVERAGE = 0.6;

    /**
     * Candidatas: fichas cuyas palabras distintivas aparecen en $terms, con su peso (rareza de las
     * coincidentes) y su cobertura (parte de su nombre que se nombró).
     *
     * @param  list<array{code: string, type: ?string, category: ?string, content: string}>  $sources
     * @param  list<string>  $terms
     * @return array<string, array{weight: float, coverage: float, category: ?string}>
     */
    public function candidates(array $sources, array $terms): array
    {
        if ($terms === []) {
            return [];
        }
        $generic = array_fill_keys(KnowledgeText::terms(implode(' ', (array) config('crm.knowledge.retrieval.generic_program_words', []))), true);

        $distinctive = [];
        foreach ($sources as $source) {
            if (($source['type'] ?? null) !== KnowledgeTaxonomy::TYPE_PROGRAM) {
                continue;
            }
            $title = preg_match('/^#\s+(.+)$/m', (string) $source['content'], $m) === 1 ? $m[1] : (string) $source['code'];
            $title = (string) preg_replace('/\s[—–-]\s.*$/u', '', $title); // «Nombre — Diploma Avanzado» → «Nombre»
            $words = array_values(array_unique(array_filter(KnowledgeText::terms($title), fn (string $t): bool => ! isset($generic[$t]))));
            if ($words !== []) {
                $distinctive[$source['code']] = ['words' => $words, 'category' => $source['category']];
            }
        }
        if ($distinctive === []) {
            return [];
        }

        // Rareza de cada palabra distintiva entre las fichas (una palabra de muchas fichas pesa poco).
        $df = [];
        foreach ($distinctive as $d) {
            foreach ($d['words'] as $w) {
                $df[$w] = ($df[$w] ?? 0) + 1;
            }
        }
        $count = count($distinctive);
        $idf = fn (string $w): float => log(1 + ($count - $df[$w] + 0.5) / ($df[$w] + 0.5));

        $candidates = [];
        foreach ($distinctive as $code => $d) {
            $matched = array_values(array_intersect($d['words'], $terms));
            if ($matched === []) {
                continue;
            }
            $weight = array_sum(array_map($idf, $matched));
            $coverage = $weight / max(0.0001, array_sum(array_map($idf, $d['words'])));
            $candidates[(string) $code] = ['weight' => $weight, 'coverage' => $coverage, 'category' => $d['category']];
        }

        return $candidates;
    }

    /**
     * Búsqueda PRECISA: la ficha (o fichas empatadas) de máxima coincidencia que cubra bastante su
     * nombre; en empate, la de la línea en curso y la de mayor cobertura. código => intensidad (0..1).
     *
     * @param  list<array{code: string, type: ?string, category: ?string, content: string}>  $sources
     * @param  list<string>  $terms
     * @return array<string, float>
     */
    public function named(array $sources, array $terms, ?string $line): array
    {
        $candidates = array_map(fn (array $c): array => ['weight' => $c['weight'], 'coverage' => $c['coverage'], 'in_line' => $line !== null && $c['category'] === $line], $this->candidates($sources, $terms));
        if ($candidates === []) {
            return [];
        }

        uasort($candidates, fn (array $a, array $b): int => [$b['weight'], $b['in_line'], $b['coverage']] <=> [$a['weight'], $a['in_line'], $a['coverage']]);
        $best = reset($candidates);
        $named = [];
        foreach ($candidates as $code => $c) {
            // Solo las de máxima coincidencia (con tolerancia) y que cubran bastante su nombre.
            if ($c['weight'] >= $best['weight'] * 0.999 && ($c['coverage'] >= 0.34 || $c['weight'] >= (float) config('crm.knowledge.retrieval.named_min_weight', 1.5))) {
                $named[$code] = round(min(1.0, 0.5 + $c['coverage'] / 2), 4);
            }
        }

        return $named;
    }

    /**
     * Búsqueda CLÁSICA: el ÚNICO programa cuya ficha se admite para esta pregunta (o ninguno).
     *
     * @param  list<array{code: string, type: ?string, category: ?string, content: string}>  $sources
     * @param  list<string>  $history  mensajes ANTERIORES del usuario, del más reciente al más antiguo
     * @return array{code: ?string, state: string, source: ?string} state: one | ambiguous | none · source: question | conversation | null
     */
    public function scope(array $sources, string $question, array $history = []): array
    {
        $now = $this->identify($sources, $question);
        if ($now['state'] !== 'none') {
            return $now + ['source' => 'question'];
        }
        // Sin programa en la pregunta: solo una continuación breve y no general hereda el contexto.
        if ($this->isGeneral($question) || ! $this->isBrief($question)) {
            return ['code' => null, 'state' => 'none', 'source' => null];
        }
        foreach (array_slice($history, 0, max(1, (int) config('crm.knowledge.retrieval.history_messages', 6))) as $previous) {
            $before = $this->identify($sources, (string) $previous);
            if ($before['state'] === 'one') {
                return $before + ['source' => 'conversation'];
            }
            // Un mensaje ambiguo, general o que cambió de tema (no breve) cierra el contexto.
            if ($before['state'] === 'ambiguous' || $this->isGeneral((string) $previous) || ! $this->isBrief((string) $previous)) {
                break;
            }
        }

        return ['code' => null, 'state' => 'none', 'source' => null];
    }

    /**
     * Programa nombrado en UN texto: código exacto o nombre (completo o parte distintiva). Si puede
     * ser más de uno, ambiguo: nunca se elige uno arbitrariamente.
     *
     * @param  list<array{code: string, type: ?string, category: ?string, content: string}>  $sources
     * @return array{code: ?string, state: string}
     */
    private function identify(array $sources, string $text): array
    {
        $programs = array_values(array_filter($sources, fn (array $s): bool => ($s['type'] ?? null) === KnowledgeTaxonomy::TYPE_PROGRAM));

        // 1) Código exacto de la ficha (MC-001), como palabra completa.
        $codes = array_values(array_unique(array_map(fn (array $s): string => (string) $s['code'], array_filter($programs,
            fn (array $s): bool => preg_match('/(?<![\p{L}\p{N}])'.preg_quote((string) $s['code'], '/').'(?![\p{L}\p{N}])/iu', $text) === 1))));
        if ($codes !== []) {
            return count($codes) === 1 ? ['code' => $codes[0], 'state' => 'one'] : ['code' => null, 'state' => 'ambiguous'];
        }

        // 2) Nombre: mismas candidatas y umbrales que la búsqueda precisa.
        $min = (float) config('crm.knowledge.retrieval.named_min_weight', 1.5);
        $qualified = array_filter($this->candidates($programs, KnowledgeText::queryTerms($text)), fn (array $c): bool => $c['coverage'] >= 0.34 || $c['weight'] >= $min);
        if ($qualified === []) {
            return ['code' => null, 'state' => 'none'];
        }
        uasort($qualified, fn (array $a, array $b): int => [$b['weight'], $b['coverage']] <=> [$a['weight'], $a['coverage']]);
        $bestCode = (string) array_key_first($qualified);
        $best = $qualified[$bestCode];
        foreach ($qualified as $code => $c) {
            // Empate con la mejor (palabra de varios nombres: «dirección», «marketing»…) u otro
            // programa nombrado de forma sustancial («compara X con Y») → ambiguo.
            if ((string) $code !== $bestCode && ($c['weight'] >= $best['weight'] * 0.999 || $c['coverage'] >= self::SECOND_PROGRAM_COVERAGE)) {
                return ['code' => null, 'state' => 'ambiguous'];
            }
        }

        return ['code' => $bestCode, 'state' => 'one'];
    }

    /** ¿Pregunta GENERAL? («…en general», «todas…», o una línea en plural: «las microcredenciales»). */
    private function isGeneral(string $text): bool
    {
        $words = KnowledgeText::words($text);
        if (array_intersect($words, self::GENERAL_WORDS) !== []) {
            return true;
        }
        foreach ((array) config('crm.knowledge.retrieval.line_terms', []) as $phrases) {
            foreach ((array) $phrases as $phrase) {
                $normalized = implode(' ', KnowledgeText::words((string) $phrase));
                if (str_ends_with($normalized, 's') && strlen($normalized) > 3 && str_contains(' '.implode(' ', $words).' ', ' '.$normalized.' ')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** ¿Continuación breve? («¿cuánto dura?», «¿y la modalidad?»). */
    private function isBrief(string $text): bool
    {
        return count(KnowledgeText::queryTerms($text)) <= self::BRIEF_TERMS;
    }
}
