<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Str;
use Modules\Ai\Models\KnowledgeSource;

/**
 * Recuperacion de conocimiento (Forma A: cuerpo pequeno y estable). Reune las
 * fuentes activas del bot, las trocea por secciones (## encabezados) y entrega al
 * modelo solo las MAS relevantes a la pregunta, acotadas por config
 * (crm.celia.knowledge_sections) para controlar el costo de tokens.
 */
class KnowledgeRetriever
{
    /**
     * Devuelve un bloque de conocimiento compacto y localizado para el prompt.
     */
    public function retrieve(int $botId, string $question, string $locale, ?int $limit = null): string
    {
        return $this->retrieveWithSources($botId, $question, $locale, $limit)['text'];
    }

    /**
     * Igual que retrieve(), y además los códigos de las fuentes cuyas secciones entraron en el
     * bloque (trazabilidad interna de la respuesta; nunca se envían al usuario).
     *
     * @return array{text: string, sources: array<int, string>}
     */
    public function retrieveWithSources(int $botId, string $question, string $locale, ?int $limit = null): array
    {
        $limit ??= (int) config('crm.celia.knowledge_sections', 3);

        // Centro de Conocimiento: fuentes ACTIVAS asignadas a este bot en el pivote con
        // is_active=true (una fuente puede compartirse entre varios bots). Mismo orden por
        // priority; el troceo por "## " y el scoring no cambian.
        $sources = KnowledgeSource::query()
            ->where('knowledge_sources.status', 'active')
            ->join('bot_knowledge_source as bks', 'bks.knowledge_source_id', '=', 'knowledge_sources.id')
            ->where('bks.bot_id', $botId)
            ->where('bks.is_active', true)
            ->orderByDesc('knowledge_sources.priority')
            ->select('knowledge_sources.*')
            ->get();

        if ($sources->isEmpty()) {
            return ['text' => '', 'sources' => []];
        }

        /** @var array<int, array{title: string, body: string, priority: int, code: string}> $sections */
        $sections = [];
        foreach ($sources as $source) {
            $content = (string) $source->translate('content', $locale);
            foreach ($this->splitSections($content) as $section) {
                $sections[] = $section + ['priority' => (int) ($source->priority ?? 0), 'code' => (string) $source->code];
            }
        }

        if ($sections === []) {
            return ['text' => '', 'sources' => []];
        }

        $tokens = $this->tokenize($question);

        // Puntua cada seccion por solape de palabras con la pregunta; desempata por prioridad.
        usort($sections, function (array $a, array $b) use ($tokens): int {
            $sa = $this->score($a, $tokens);
            $sb = $this->score($b, $tokens);

            return $sa === $sb ? ($b['priority'] <=> $a['priority']) : ($sb <=> $sa);
        });

        $chosen = array_slice($sections, 0, max(1, $limit));

        return [
            'text' => trim(implode("\n\n", array_map(
                fn (array $s) => trim($s['title']."\n".$s['body']),
                $chosen,
            ))),
            'sources' => array_values(array_unique(array_filter(array_column($chosen, 'code')))),
        ];
    }

    /**
     * Trocea un markdown en secciones por encabezados "## ". El preambulo (antes
     * del primer ##) se conserva como una seccion sin titulo.
     *
     * @return array<int, array{title: string, body: string}>
     */
    private function splitSections(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $lines = preg_split('/\r?\n/', $content) ?: [];

        // Se agrupan las lineas en bloques: cada "## " abre un bloque nuevo. El
        // preambulo (antes del primer ##) queda como un bloque con titulo vacio.
        /** @var array<int, array{title: string, lines: array<int,string>}> $blocks */
        $blocks = [['title' => '', 'lines' => []]];
        foreach ($lines as $line) {
            if (Str::startsWith(ltrim($line), '## ')) {
                $blocks[] = ['title' => trim($line), 'lines' => []];

                continue;
            }
            $blocks[count($blocks) - 1]['lines'][] = $line;
        }

        $sections = [];
        foreach ($blocks as $block) {
            $bodyText = trim(implode("\n", $block['lines']));
            if ($block['title'] !== '' || $bodyText !== '') {
                $sections[] = ['title' => $block['title'], 'body' => $bodyText];
            }
        }

        return $sections;
    }

    /**
     * @param  array{title: string, body: string, priority?: int}  $section
     * @param  array<int,string>  $tokens
     */
    private function score(array $section, array $tokens): int
    {
        if ($tokens === []) {
            return 0;
        }

        $haystack = ' '.$this->normalize($section['title'].' '.$section['body']).' ';
        $score = 0;
        foreach ($tokens as $token) {
            if (str_contains($haystack, ' '.$token.' ') || str_contains($haystack, $token)) {
                $score++;
            }
        }

        return $score;
    }

    /**
     * @return array<int,string>
     */
    private function tokenize(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $this->normalize($text)) ?: [];

        return array_values(array_filter($words, fn (string $w) => mb_strlen($w) >= 4));
    }

    private function normalize(string $text): string
    {
        return Str::of($text)->lower()->ascii()->toString();
    }
}
