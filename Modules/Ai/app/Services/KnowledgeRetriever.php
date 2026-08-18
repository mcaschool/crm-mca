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
        $limit ??= (int) config('crm.celia.knowledge_sections', 3);

        $sources = KnowledgeSource::query()
            ->where('bot_id', $botId)
            ->where('status', 'active')
            ->orderByDesc('priority')
            ->get();

        if ($sources->isEmpty()) {
            return '';
        }

        /** @var array<int, array{title: string, body: string, priority: int}> $sections */
        $sections = [];
        foreach ($sources as $source) {
            $content = (string) $source->translate('content', $locale);
            foreach ($this->splitSections($content) as $section) {
                $sections[] = $section + ['priority' => (int) ($source->priority ?? 0)];
            }
        }

        if ($sections === []) {
            return '';
        }

        $tokens = $this->tokenize($question);

        // Puntua cada seccion por solape de palabras con la pregunta; desempata por prioridad.
        usort($sections, function (array $a, array $b) use ($tokens): int {
            $sa = $this->score($a, $tokens);
            $sb = $this->score($b, $tokens);

            return $sa === $sb ? ($b['priority'] <=> $a['priority']) : ($sb <=> $sa);
        });

        $chosen = array_slice($sections, 0, max(1, $limit));

        return trim(implode("\n\n", array_map(
            fn (array $s) => trim($s['title']."\n".$s['body']),
            $chosen,
        )));
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
