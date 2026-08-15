<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

/**
 * Reporte del importador de catalogo. Acumula el resultado por fila para
 * imprimir al final: creados, actualizados, incompletos (con motivo) y omitidos.
 */
final class ImportReport
{
    public int $created = 0;

    public int $updated = 0;

    /** @var array<int, array{code: string, reason: string}> Filas importadas pero marcadas para revision. */
    public array $incomplete = [];

    /** @var array<int, array{row: int, reason: string}> Filas que no se pudieron importar. */
    public array $skipped = [];

    public function created(): void
    {
        $this->created++;
    }

    public function updated(): void
    {
        $this->updated++;
    }

    public function incomplete(string $code, string $reason): void
    {
        $this->incomplete[] = ['code' => $code, 'reason' => $reason];
    }

    public function skipped(int $row, string $reason): void
    {
        $this->skipped[] = ['row' => $row, 'reason' => $reason];
    }

    public function totalProcessed(): int
    {
        return $this->created + $this->updated;
    }
}
