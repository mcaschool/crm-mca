<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Str;
use Modules\Ai\Support\KnowledgeTaxonomy;
use Modules\Ai\Support\KnowledgeText;

/**
 * Búsqueda «precisa» en el conocimiento de un asesor (activable por asesor; Celia sigue con la
 * clásica). Puro: recibe las fuentes y la conversación, no toca la base de datos.
 *
 * Puntuación de cada sección (## del markdown):
 *  1. Relevancia BM25 con peso por RAREZA de cada palabra (IDF) y el título contando el doble.
 *     Se normaliza a 0..1 respecto a la mejor sección de la pregunta.
 *  2. + bonus de FICHA NOMBRADA: si la pregunta nombra un programa concreto (palabras distintivas
 *     del título de su ficha: «marketing», «alta dirección», «coaching»…), sus secciones priman
 *     sobre las generales. Si no nombra ninguno, priman los documentos generales de la línea.
 *  3. + bonus de DEFINICIÓN: a «¿qué es…?» o «información de…» responden las secciones que definen
 *     («Qué es…», «Información general…»), no las que solo mencionan el nombre.
 *  4. + bonus de LÍNEA: la que nombra la pregunta («diploma avanzado», «Micro MBA»…) o, en un
 *     seguimiento sin línea («¿y cuánto cuesta?»), la del TEMA ACTIVO de la conversación. Si la
 *     pregunta compara dos o más líneas, no se favorece ninguna.
 * Los preámbulos vacíos (solo el título del documento) no compiten. Desempate DETERMINISTA:
 * puntaje, prioridad, código y orden de la sección en su documento.
 */
final class PreciseKnowledgeRanker
{
    private const K1 = 1.2;

    private const B = 0.75;

    /**
     * @param  list<array{code: string, priority: int, category: ?string, type: ?string, content: string}>  $sources
     * @param  list<string>  $history  mensajes ANTERIORES del usuario, del más reciente al más antiguo
     * @return array{sections: list<array{code: string, title: string, body: string, score: float}>, diagnostics: array<string, mixed>}
     */
    public function rank(array $sources, string $question, array $history = [], int $limit = 3): array
    {
        $cfg = (array) config('crm.knowledge.retrieval', []);
        $sections = $this->sections($sources);
        $terms = KnowledgeText::queryTerms($question);

        // Línea: la nombrada en la pregunta; si no nombra ninguna, la del tema activo.
        ['line' => $line, 'source' => $lineSource] = $this->topic($question, $history);

        // Programa en foco: el que nombra la pregunta o, en un seguimiento breve («requisitos»,
        // «precio», «cómo me inscribo»), el último elegido/nombrado en la conversación. Si fija el
        // foco y aún no hay línea, su línea pasa a ser la línea en curso (para el filtro).
        $focus = $this->focusProgram($sources, $question, $history);
        if ($line === null && $focus !== null && $focus['category'] !== null) {
            $line = $focus['category'];
            $lineSource = 'program';
        }

        $named = $this->namedPrograms($sources, $terms, $line);
        // El programa en foco heredado del contexto cuenta como nombrado: sus secciones priman.
        if ($named === [] && $focus !== null) {
            $named[$focus['code']] = 1.0;
        }

        // Sinónimos de recuperación: la pregunta busca también por los términos equivalentes del grupo
        // que toque, con un peso menor que el original.
        $weighted = $this->weightedTerms($terms);
        $relevance = $this->bm25($sections, $weighted);

        // FILTRO POR LÍNEA: con una línea identificada, la recuperación se ACOTA SIEMPRE a esa línea más
        // «General institucional»; NUNCA se reabren otras líneas de programa (DA, PE, MMBA…). Si la línea
        // no aporta ninguna sección por encima del umbral, los resultados (si hay) salen de «General
        // institucional» (diagnóstico: «expanded»); si tampoco hay, se devuelve vacío y el asesor dice
        // que no tiene el dato.
        $institutional = KnowledgeTaxonomy::institutionalLine();
        $inPool = fn (int $i): bool => $sections[$i]['category'] === $line || $sections[$i]['category'] === $institutional;
        $applyFilter = $line !== null && (bool) ($cfg['line_filter'] ?? true);
        $lineMin = (float) ($cfg['line_filter_min_relevance'] ?? 0.0);
        $lineHasHit = false;
        if ($applyFilter) {
            foreach (array_keys($sections) as $i) {
                if ($sections[$i]['category'] === $line && $relevance[$i] > $lineMin) {
                    $lineHasHit = true;
                    break;
                }
            }
        }
        $expanded = $applyFilter && ! $lineHasHit;   // la línea no aportó nada: solo «General institucional»

        $lineBonus = (float) ($cfg['line_bonus'] ?? 0.5);
        $namedBonus = (float) ($cfg['named_program_bonus'] ?? 0.6);
        $generalBonus = (float) ($cfg['general_document_bonus'] ?? 0.15);
        // Pregunta de DEFINICIÓN («qué es…», «información de…»): priman las secciones que definen.
        $definition = KnowledgeText::asksDefinition($question);
        $definitionBonus = (float) ($cfg['definition_bonus'] ?? 0.3);

        // La normalización 0..1 se mide solo sobre las secciones elegibles (las de la línea cuando se
        // filtra), para que el filtro no dependa de una sección de otra línea con mayor relevancia.
        $eligibleRelevance = [];
        foreach (array_keys($sections) as $i) {
            if (! $applyFilter || $inPool($i)) {
                $eligibleRelevance[] = $relevance[$i];
            }
        }
        $max = max([0.0, ...$eligibleRelevance]);

        foreach ($sections as $i => &$s) {
            if ($applyFilter && ! $inPool($i)) {
                $s['score'] = 0.0;   // fuera de la línea en curso + General institucional: no compite
                continue;
            }
            $score = $max > 0 ? $relevance[$i] / $max : 0.0;
            if ($score > 0) {
                if ($line !== null && $s['category'] === $line) {
                    $score += $lineBonus;
                }
                if (isset($named[$s['code']])) {
                    $score += $namedBonus * $named[$s['code']];
                } elseif ($named === [] && $s['type'] === 'base_conocimiento') {
                    $score += $generalBonus;
                }
                if ($definition && KnowledgeText::isDefinitionTitle($s['title'])) {
                    $score += $definitionBonus;
                }
            }
            $s['score'] = round($score, 4);
        }
        unset($s);

        usort($sections, fn (array $a, array $b): int => [$b['score'], $b['priority'], $a['code'], $a['order']] <=> [$a['score'], $a['priority'], $b['code'], $b['order']]);
        $chosen = array_values(array_filter(array_slice($sections, 0, max(1, $limit)), fn (array $s): bool => $s['score'] > 0));

        return [
            'sections' => array_map(fn (array $s): array => ['code' => $s['code'], 'title' => $s['title'], 'body' => $s['body'], 'score' => $s['score']], $chosen),
            'diagnostics' => [
                'terms' => $terms,
                'line' => $line,
                'line_source' => $lineSource,
                'line_applied' => $applyFilter,
                'expanded' => $expanded,
                'focus_program' => $focus['code'] ?? null,
                'named_programs' => array_keys($named),
                'top' => array_map(fn (array $s): array => ['code' => $s['code'], 'title' => Str::limit(ltrim($s['title'], '# '), 80), 'score' => $s['score']], array_slice($sections, 0, 5)),
            ],
        ];
    }

    /**
     * Programa en FOCO de la conversación: el que nombra la pregunta o, en un seguimiento breve y no
     * general, el último elegido/nombrado en el contexto reciente. Reutiliza el mismo detector que la
     * búsqueda clásica (ProgramMentions::scope), de modo que ambos coinciden en qué es «un» programa.
     *
     * @param  list<array{code: string, priority: int, category: ?string, type: ?string, content: string}>  $sources
     * @param  list<string>  $history
     * @return array{code: string, category: ?string}|null
     */
    private function focusProgram(array $sources, string $question, array $history): ?array
    {
        $programs = array_values(array_filter($sources, fn (array $s): bool => ($s['type'] ?? null) === KnowledgeTaxonomy::TYPE_PROGRAM));
        if ($programs === []) {
            return null;
        }
        $scope = app(ProgramMentions::class)->scope($programs, $question, $history);
        if ($scope['state'] !== 'one' || $scope['code'] === null) {
            return null;
        }
        foreach ($programs as $p) {
            if ((string) $p['code'] === $scope['code']) {
                return ['code' => $scope['code'], 'category' => $p['category'] !== null ? (string) $p['category'] : null];
            }
        }

        return ['code' => $scope['code'], 'category' => null];
    }

    /**
     * Línea en curso: la que nombra la pregunta (si nombra una sola) o, si no nombra ninguna, la
     * del TEMA ACTIVO (el mensaje anterior más reciente que nombre una sola línea). Si la pregunta
     * compara líneas, ninguna.
     *
     * @param  list<string>  $history  mensajes anteriores del usuario, del más reciente al más antiguo
     * @return array{line: ?string, source: ?string} source: question | conversation | null
     */
    public function topic(string $question, array $history = []): array
    {
        $explicit = KnowledgeText::linesMentioned($question);
        if ($explicit !== []) {
            return count($explicit) === 1 ? ['line' => $explicit[0], 'source' => 'question'] : ['line' => null, 'source' => null];
        }
        foreach ($history as $previous) {
            $lines = KnowledgeText::linesMentioned((string) $previous);
            if ($lines !== []) {
                // Un mensaje que compara líneas no fija tema.
                return count($lines) === 1 ? ['line' => $lines[0], 'source' => 'conversation'] : ['line' => null, 'source' => null];
            }
        }

        return ['line' => null, 'source' => null];
    }

    /**
     * Parecido (0..1) entre una pregunta y otras (las de las respuestas aprobadas): coseno de sus
     * CONCEPTOS (raíces con sinónimos agrupados) ponderados por rareza. La rareza se mide sobre
     * $corpus (las secciones del conocimiento del asesor) más las propias preguntas, para que una
     * palabra muy repetida («programa», «diploma») pese poco y una distintiva pese mucho.
     *
     * @param  list<string>  $candidates
     * @param  list<string>  $corpus
     * @return list<float>
     */
    public function questionSimilarity(string $question, array $candidates, array $corpus = []): array
    {
        // Erratas de la pregunta («tuenen», «disponibel»): se corrigen contra el vocabulario del
        // conocimiento del asesor y de las propias preguntas aprobadas antes de comparar.
        $question = KnowledgeText::correctTypos($question, KnowledgeText::vocabulary(array_merge($candidates, $corpus)));
        $q = KnowledgeText::concepts($question);
        $docs = array_map(fn (string $c): array => KnowledgeText::concepts($c), $candidates);
        if ($q === [] || $docs === []) {
            return array_fill(0, count($candidates), 0.0);
        }

        $all = array_merge([$q], $docs, array_map(fn (string $t): array => array_values(array_unique(KnowledgeText::concepts($t))), $corpus));
        $n = count($all);
        $df = [];
        foreach ($all as $terms) {
            foreach (array_unique($terms) as $t) {
                $df[$t] = ($df[$t] ?? 0) + 1;
            }
        }
        $w = fn (string $t): float => log(1 + ($n - $df[$t] + 0.5) / ($df[$t] + 0.5));
        $norm = fn (array $terms): float => sqrt(array_sum(array_map(fn (string $t): float => $w($t) ** 2, $terms)));
        $qNorm = $norm($q);

        return array_map(function (array $terms) use ($q, $w, $norm, $qNorm): float {
            $shared = array_intersect($q, $terms);
            $den = $qNorm * $norm($terms);

            return $den > 0 ? round(array_sum(array_map(fn (string $t): float => $w($t) ** 2, $shared)) / $den, 4) : 0.0;
        }, $docs);
    }

    /**
     * Secciones de las fuentes (por «## »), sin los preámbulos vacíos.
     *
     * @param  list<array{code: string, priority: int, category: ?string, type: ?string, content: string}>  $sources
     * @return list<array{code: string, priority: int, category: ?string, type: ?string, title: string, body: string, order: int, title_terms: list<string>, body_terms: list<string>}>
     */
    private function sections(array $sources): array
    {
        $out = [];
        foreach ($sources as $source) {
            $blocks = [['title' => '', 'lines' => []]];
            foreach (preg_split('/\r?\n/', trim((string) $source['content'])) ?: [] as $l) {
                if (str_starts_with(ltrim($l), '## ')) {
                    $blocks[] = ['title' => trim($l), 'lines' => []];

                    continue;
                }
                $blocks[count($blocks) - 1]['lines'][] = $l;
            }
            foreach ($blocks as $order => $block) {
                $body = trim(implode("\n", $block['lines']));
                // Preámbulo sin contenido real: solo comentarios y el título «# » del documento.
                $real = trim((string) preg_replace(['/<!--.*?-->/s', '/^\s*#\s.*$/m'], '', $body));
                if ($block['title'] === '' && $real === '') {
                    continue;
                }
                $out[] = [
                    'code' => (string) $source['code'], 'priority' => (int) $source['priority'],
                    'category' => $source['category'], 'type' => $source['type'],
                    'title' => $block['title'], 'body' => $body, 'order' => $order,
                    // El preámbulo puntúa solo por su contenido real (no por el título del documento).
                    'title_terms' => KnowledgeText::terms($block['title']), 'body_terms' => KnowledgeText::terms($block['title'] === '' ? $real : $body),
                ];
            }
        }

        return $out;
    }

    /**
     * BM25 con el título contando el doble (frecuencia ponderada) y la rareza de cada palabra
     * calculada sobre las secciones de ESTE asesor. Cada término lleva un peso (1.0 el original de la
     * pregunta; menor los sinónimos de recuperación añadidos), que multiplica su aporte al puntaje.
     *
     * @param  list<array{title_terms: list<string>, body_terms: list<string>}>  $sections
     * @param  array<string, float>  $weighted  término => peso
     * @return list<float>
     */
    private function bm25(array $sections, array $weighted): array
    {
        $n = count($sections);
        $terms = array_keys($weighted);
        if ($n === 0 || $terms === []) {
            return array_fill(0, $n, 0.0);
        }
        $titleWeight = (float) config('crm.knowledge.retrieval.title_weight', 2.0);

        $tf = [];
        $df = array_fill_keys($terms, 0);
        $lengths = [];
        foreach ($sections as $i => $s) {
            $counts = [];
            foreach ($s['title_terms'] as $t) {
                $counts[$t] = ($counts[$t] ?? 0) + $titleWeight;
            }
            foreach ($s['body_terms'] as $t) {
                $counts[$t] = ($counts[$t] ?? 0) + 1;
            }
            $tf[$i] = $counts;
            $lengths[$i] = count($s['body_terms']) + $titleWeight * count($s['title_terms']);
            foreach ($terms as $t) {
                if (isset($counts[$t])) {
                    $df[$t]++;
                }
            }
        }
        $avg = max(1.0, array_sum($lengths) / $n);

        $scores = [];
        foreach ($sections as $i => $s) {
            $score = 0.0;
            foreach ($terms as $t) {
                $f = $tf[$i][$t] ?? 0;
                if ($f <= 0) {
                    continue;
                }
                $idf = log(1 + ($n - $df[$t] + 0.5) / ($df[$t] + 0.5));
                $score += $weighted[$t] * $idf * ($f * (self::K1 + 1)) / ($f + self::K1 * (1 - self::B + self::B * $lengths[$i] / $avg));
            }
            $scores[] = $score;
        }

        return $scores;
    }

    /**
     * Términos de búsqueda con su peso: los de la pregunta a 1.0 y, por cada grupo de sinónimos de
     * recuperación (config) que la pregunta toque, sus demás raíces a `synonym_weight` (< 1). Las
     * frases de la config se reducen a raíces con el mismo normalizador del conocimiento.
     *
     * @param  list<string>  $terms
     * @return array<string, float>
     */
    private function weightedTerms(array $terms): array
    {
        $weighted = [];
        foreach ($terms as $t) {
            $weighted[$t] = 1.0;
        }
        $synWeight = (float) config('crm.knowledge.retrieval.synonym_weight', 0.5);
        foreach ((array) config('crm.knowledge.retrieval.synonyms', []) as $key => $members) {
            $groupStems = [];
            foreach (array_merge([(string) $key], (array) $members) as $phrase) {
                foreach (KnowledgeText::terms((string) $phrase) as $stem) {
                    $groupStems[$stem] = true;
                }
            }
            // Solo se expande si la pregunta ya contiene alguna raíz del grupo.
            if (array_intersect(array_keys($groupStems), $terms) === []) {
                continue;
            }
            foreach (array_keys($groupStems) as $stem) {
                $weighted[$stem] ??= $synWeight;   // no rebaja el peso de un término original
            }
        }

        return $weighted;
    }

    /**
     * Fichas de programa que la pregunta NOMBRA: comparten palabras distintivas con el título «# »
     * de la ficha (sin las genéricas: diploma, programa, ejecutivo…). Gana la de mayor peso de
     * coincidencia; en empate, la de la línea en curso y la de mayor cobertura de su nombre.
     *
     * @param  list<array{code: string, priority: int, category: ?string, type: ?string, content: string}>  $sources
     * @param  list<string>  $terms
     * @return array<string, float> código => intensidad (0..1)
     */
    private function namedPrograms(array $sources, array $terms, ?string $line): array
    {
        // Detector compartido con la búsqueda clásica (un solo algoritmo de candidatas).
        return app(ProgramMentions::class)->named($sources, $terms, $line);
    }
}
