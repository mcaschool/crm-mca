<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Collection;
use Modules\Catalog\Models\Program;

/**
 * Carga masiva por línea (Programa Académico): resuelve el programa del catálogo de CADA
 * archivo .md, en este orden:
 *   a) campo «Programa» del comentario de metadatos, comparado con programs.code;
 *   b) si no lo hay, las URLs del contenido comparadas con programs.url (sin distinguir
 *      http/https, mayúsculas ni la barra final). Solo vale si coincide UN programa.
 * Si no se resuelve, hay varios candidatos, o el programa no existe o está inactivo, se
 * devuelve el motivo y el archivo se rechaza (los demás siguen).
 *
 * Todo pasa por Program::query(): el scope de institución (BelongsToInstitution) acota a la
 * institución actual y SoftDeletes excluye los borrados, así que nunca se asigna un programa
 * de otra institución.
 */
class KnowledgeProgramResolver
{
    /** @var Collection<int, Program>|null Programas de la institución (se cargan una vez por instancia). */
    private ?Collection $catalog = null;

    /**
     * @return array{program: ?Program, error: ?string}
     */
    public function resolve(string $raw, ?string $programCode): array
    {
        $programCode = $programCode !== null ? trim($programCode) : '';

        if ($programCode !== '') {
            $program = $this->catalog()->first(fn (Program $p): bool => mb_strtolower((string) $p->code) === mb_strtolower($programCode));
            if ($program === null) {
                return $this->fail(__('Programa :code no existe', ['code' => $programCode]));
            }

            return $program->status === 'active'
                ? ['program' => $program, 'error' => null]
                : $this->fail(__('Programa :code está inactivo', ['code' => $program->code]));
        }

        $urls = $this->urlsIn($raw);
        $matches = $urls === []
            ? collect()
            : $this->catalog()->filter(fn (Program $p): bool => in_array($this->normalizeUrl((string) $p->url), $urls, true))->values();

        if ($matches->isEmpty()) {
            return $this->fail(__('No se encontró programa para este archivo'));
        }
        if ($matches->count() > 1) {
            return $this->fail(__('Varios programas coinciden (:codes)', ['codes' => $matches->pluck('code')->implode(', ')]));
        }

        /** @var Program $program */
        $program = $matches->first();

        return $program->status === 'active'
            ? ['program' => $program, 'error' => null]
            : $this->fail(__('Programa :code está inactivo', ['code' => $program->code]));
    }

    /** URL comparable: sin esquema http(s), en minúsculas y sin barra final. */
    public function normalizeUrl(string $url): string
    {
        $url = mb_strtolower(trim($url));
        $url = (string) preg_replace('#^https?://#', '', $url);

        return rtrim($url, '/');
    }

    /**
     * URLs http(s) presentes en el contenido, normalizadas y sin repetir. Se recorta la
     * puntuación final típica de Markdown/prosa (paréntesis, comas, puntos).
     *
     * @return array<int, string>
     */
    private function urlsIn(string $raw): array
    {
        preg_match_all('#https?://[^\s<>"\'()\[\]]+#i', $raw, $m);

        return array_values(array_unique(array_map(
            fn (string $u): string => $this->normalizeUrl(rtrim($u, '.,;:!?*_')),
            $m[0],
        )));
    }

    /** @return Collection<int, Program> */
    private function catalog(): Collection
    {
        return $this->catalog ??= Program::query()->get(['id', 'code', 'name_es', 'url', 'status', 'line']);
    }

    /** @return array{program: null, error: string} */
    private function fail(string $reason): array
    {
        return ['program' => null, 'error' => $reason];
    }
}
