<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Collection;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Support\KnowledgeTaxonomy;
use Modules\Ai\Support\KnowledgeText;
use Modules\Catalog\Models\Program;

/**
 * Preguntas de CATÁLOGO («¿qué programas tienen?», «¿cuáles diplomas hay disponibles?», «dame el
 * link de los programas ejecutivos»): la respuesta se construye SIN IA desde los datos autorizados,
 * nunca desde las secciones que haya recuperado la búsqueda (que no son el catálogo completo):
 *
 *  - programas ACTIVOS asignados al asesor (bot_program), agrupados por línea;
 *  - enlace oficial de la línea: knowledge_sources.url (https) de la fuente «base_conocimiento»
 *    ACTIVA, asignada al asesor y de esa línea; si hay varias, la de mayor prioridad. Sin enlace
 *    confirmado se responde sin enlace (y queda en la traza): nunca se inventa ni se deduce.
 *
 * Con una línea: la lista completa de nombres + el enlace. Sin línea: las líneas con su cantidad de
 * programas y su enlace, y una pregunta para elegir. El texto se valida contra el límite seguro del
 * canal: si la lista no cabe, se responde la versión compacta (cantidad + enlace), nunca cortada.
 */
final class AdvisorCatalog
{
    /** Palabras de programa en plural (normalizadas) que hacen de un listado una pregunta de catálogo. */
    private const NOUNS = 'programas|diplomas|diplomados|microcredenciales|cursos|maestrias|doctorados|carreras|formaciones|programs|courses|degrees|microcredentials';

    /** Palabras que pueden ir entre el interrogativo y el sustantivo («qué TIPOS DE programas»). */
    private const FILLERS = 'son|los|las|el|la|de|tipo|tipos|clase|clases|otros|otras|mas|other|kind|kinds|of|the|are';

    public function __construct(private readonly ProgramAssignmentService $assignments) {}

    /** ¿Es una pregunta de catálogo / listado? (determinista, sin IA) */
    public static function isCatalogQuestion(string $text): bool
    {
        $w = ' '.implode(' ', KnowledgeText::words($text)).' ';
        if (preg_match('/ (?:'.self::NOUNS.') /', $w) !== 1) {
            return false;
        }
        // «qué / cuáles / cuántos (son los) programas…», «what / which programs…»
        if (preg_match('/ (?:que|cuales|cuantos|cuantas|what|which)(?: (?:'.self::FILLERS.'))* (?:'.self::NOUNS.') /', $w) === 1) {
            return true;
        }

        // Pistas de listado en cualquier parte junto al sustantivo en plural.
        return preg_match('/ (?:disponibles?|oferta|ofrecen|catalogo|lista|listado|todos|todas|link|enlace|pagina|web|url|available|offer|catalog|list|all) /', $w) === 1;
    }

    /**
     * Respuesta de catálogo para la pregunta, o null si no lo es (o el asesor no tiene programas
     * en lo que se pregunta: entonces responde la IA con su conocimiento, como siempre).
     *
     * @param  array<string, mixed>|null  $previous  traza «catalog» de la respuesta anterior del asesor
     * @return array{reply: string, trace: array<string, mixed>}|null
     */
    public function answer(int $botId, string $question, string $locale, string $channel, ?array $previous = null): ?array
    {
        $mentioned = KnowledgeText::linesMentioned($question);
        $catalog = self::isCatalogQuestion($question);
        // Seguimiento del resumen («¿cuál te gustaría consultar?» → «los ejecutivos»): basta nombrar una línea.
        $followUp = ! $catalog && ($previous['mode'] ?? null) === 'summary' && count($mentioned) === 1 && count(KnowledgeText::words($question)) <= 6;
        if (! $catalog && ! $followUp) {
            return null;
        }

        $lines = $this->linesOf($botId, $locale);
        if ($lines === []) {
            return null;
        }
        $limit = $this->limitFor($channel);

        if (count($mentioned) === 1) {
            $line = $mentioned[0];
            if (! isset($lines[$line])) {
                return null; // el asesor no ofrece esa línea: que responda la IA con su conocimiento
            }

            return $this->lineAnswer($botId, $line, $lines[$line], $locale, $limit);
        }

        // Sin línea (o comparando varias): las líneas con su cantidad y enlace, y una pregunta.
        $only = $mentioned === [] ? $lines : array_intersect_key($lines, array_flip($mentioned));

        return $this->summaryAnswer($botId, $only === [] ? $lines : $only, $locale, $limit);
    }

    /**
     * Índice breve del catálogo activo del asesor para el prompt: las secciones recuperadas no son
     * el catálogo, y la IA no debe concluir que existe un solo programa de una línea con varios.
     */
    public function promptIndex(int $botId, string $locale): ?string
    {
        $lines = $this->linesOf($botId, $locale);
        if ($lines === []) {
            return null;
        }
        $parts = [];
        foreach ($lines as $line => $programs) {
            $parts[] = __((string) KnowledgeTaxonomy::lineLabel($line), [], $locale).': '.count($programs);
        }

        return $locale === 'en'
            ? 'ACTIVE CATALOG OF THIS ADVISOR (programs per line): '.implode(' · ', $parts).'. The knowledge sections are NOT the full catalog: never say that a line has a single program when it has several.'
            : 'CATÁLOGO ACTIVO DE ESTE ASESOR (programas por línea): '.implode(' · ', $parts).'. Las secciones del conocimiento NO son el catálogo completo: nunca afirmes que una línea tiene un solo programa cuando tiene varios.';
    }

    /**
     * @param  list<string>  $names
     * @return array{reply: string, trace: array<string, mixed>}
     */
    private function lineAnswer(int $botId, string $line, array $names, string $locale, int $limit): array
    {
        $label = __((string) KnowledgeTaxonomy::lineLabel($line), [], $locale);
        [$url, $source] = $this->officialLink($botId, $line);
        $count = count($names);

        $list = implode("\n", array_merge(
            [$count === 1 ? trans('celia.catalog_list_one', ['line' => $label], $locale) : trans('celia.catalog_list', ['count' => $count, 'line' => $label], $locale)],
            array_map(fn (string $n): string => '• '.$n, $names),
        ));
        $tail = [];
        if ($url !== null) {
            $tail[] = trans($count === 1 ? 'celia.catalog_link_one' : 'celia.catalog_link', ['url' => $url], $locale);
        }
        if ($count > 1) {
            $tail[] = trans('celia.catalog_list_closing', [], $locale);
        }
        $reply = $list.($tail !== [] ? "\n\n".implode("\n", $tail) : '');
        $mode = 'list';

        // Si la lista no cabe en el canal, la versión compacta (nunca una lista cortada).
        if (mb_strlen($reply) > $limit) {
            $mode = 'compact';
            $reply = implode(' ', array_filter([
                trans('celia.catalog_compact', ['count' => $count, 'line' => $label], $locale),
                $url !== null ? trans('celia.catalog_link', ['url' => $url], $locale) : null,
                trans('celia.catalog_compact_closing', [], $locale),
            ]));
        }

        return ['reply' => $reply, 'trace' => [
            'mode' => $mode, 'line' => $line, 'count' => $count, 'link_source' => $source,
            'link_missing' => $url === null ? [$line] : [], 'chars' => mb_strlen($reply), 'limit' => $limit,
        ]];
    }

    /**
     * @param  array<string, list<string>>  $lines
     * @return array{reply: string, trace: array<string, mixed>}
     */
    private function summaryAnswer(int $botId, array $lines, string $locale, int $limit): array
    {
        $rows = [trans('celia.catalog_summary_intro', [], $locale)];
        $counts = [];
        $sources = [];
        $missing = [];
        foreach ($lines as $line => $names) {
            [$url, $source] = $this->officialLink($botId, $line);
            $counts[$line] = count($names);
            $sources[$line] = $source;
            if ($url === null) {
                $missing[] = $line;
            }
            $rows[] = '• '.__((string) KnowledgeTaxonomy::lineLabel($line), [], $locale).': '
                .trans_choice('celia.catalog_summary_count', count($names), ['count' => count($names)], $locale)
                .($url !== null ? ' — '.$url : '');
        }
        $reply = implode("\n", $rows)."\n\n".trans('celia.catalog_summary_question', [], $locale);

        return ['reply' => $reply, 'trace' => [
            'mode' => 'summary', 'line' => null, 'lines' => $counts, 'count' => array_sum($counts),
            'link_sources' => $sources, 'link_missing' => $missing, 'chars' => mb_strlen($reply), 'limit' => $limit,
        ]];
    }

    /**
     * Programas ACTIVOS asignados al asesor, por línea (en el orden de las líneas configuradas) y
     * con el nombre en el idioma de la conversación.
     *
     * @return array<string, list<string>> línea => nombres
     */
    private function linesOf(int $botId, string $locale): array
    {
        /** @var Collection<int, Program> $programs */
        $programs = Program::query()
            ->whereIn('id', $this->assignments->assignedIdsQuery($botId))
            ->where('status', 'active')
            ->whereNotNull('line')
            ->orderBy('display_order')
            ->orderBy('code')
            ->get();

        $byLine = [];
        foreach (array_keys(KnowledgeTaxonomy::lines()) as $line) {
            $names = $programs->where('line', $line)
                ->map(fn (Program $p): string => trim((string) ($p->translate('name', $locale) ?: $p->name_es)))
                ->filter()
                ->values()
                ->all();
            if ($names !== []) {
                $byLine[(string) $line] = $names;
            }
        }

        return $byLine;
    }

    /**
     * Enlace oficial de la línea: url https de la fuente «base_conocimiento» activa, asignada (y
     * activa) al asesor y de esa línea; si hay varias, la de mayor prioridad (desempate: id).
     *
     * @return array{0: ?string, 1: ?string} [url, código de la fuente]
     */
    private function officialLink(int $botId, string $line): array
    {
        $source = KnowledgeSource::query()
            ->join('bot_knowledge_source as bks', 'bks.knowledge_source_id', '=', 'knowledge_sources.id')
            ->where('bks.bot_id', $botId)
            ->where('bks.is_active', true)
            ->where('knowledge_sources.status', 'active')
            ->where('knowledge_sources.type', 'base_conocimiento')
            ->where('knowledge_sources.category', $line)
            ->where('knowledge_sources.url', 'like', 'https://%')
            ->orderByDesc('knowledge_sources.priority')
            ->orderBy('knowledge_sources.id')
            ->select('knowledge_sources.*')
            ->first();

        return $source !== null ? [(string) $source->url, (string) $source->code] : [null, null];
    }

    /** Límite seguro de caracteres del canal (Messenger 2000, Instagram 1000, WhatsApp 4096…). */
    private function limitFor(string $channel): int
    {
        $limits = (array) config('crm.celia.catalog_max_chars', []);

        return (int) ($limits[$channel] ?? $limits['default'] ?? 1500);
    }
}
