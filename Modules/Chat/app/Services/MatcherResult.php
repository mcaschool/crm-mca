<?php

declare(strict_types=1);

namespace Modules\Chat\Services;

use Illuminate\Support\Collection;
use Modules\Catalog\Models\Program;

/**
 * Resultado del emparejador. `tier` indica en que nivel de degradacion se
 * encontraron los programas (1: area+nivel+meta, 2: area+meta, 3: mejores del
 * area, 4: nada -> sugerir Celia / catalogo completo).
 */
final class MatcherResult
{
    /**
     * @param  Collection<int, Program>  $programs
     */
    public function __construct(
        public readonly Collection $programs,
        public readonly int $tier,
        public readonly string $level,
        public readonly ?string $goal,
        public readonly ?int $categoryId,
    ) {}

    public function isEmpty(): bool
    {
        return $this->programs->isEmpty();
    }

    /** Nunca dejamos al usuario sin salida: si esta vacio, se invita a Celia. */
    public function suggestCelia(): bool
    {
        return $this->programs->isEmpty();
    }
}
