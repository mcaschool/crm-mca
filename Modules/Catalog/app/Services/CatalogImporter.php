<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Illuminate\Support\Str;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Catalog\Support\TagParser;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Importador idempotente del catalogo desde el Excel del cliente (hoja "Catalogo").
 *
 * - Upsert por `code` (MC-001...): re-ejecutar ACTUALIZA, no duplica.
 * - Tolera imperfecciones conocidas: filas incompletas se importan con lo que
 *   haya, se marcan inactivas / para revision y se reportan; nunca falla en seco.
 * - Requiere una institucion activa en el contexto (lo fija el comando).
 *
 * NO importa la columna "Aprendizajes" (el detalle vive en la web).
 */
class CatalogImporter
{
    /** field => posibles cabeceras normalizadas (minusculas, sin acentos). */
    private const COLUMNS = [
        'code' => ['id'],
        'name_es' => ['nombre'],
        'credential_en' => ['microcredencial que otorga', 'microcredencial', 'credencial'],
        'category' => ['area'],
        'duration' => ['duracion'],
        'modality' => ['modalidad'],
        'description' => ['descripcion'],
        'url' => ['url'],
        'status' => ['activo', 'estado'],
        'order' => ['peso', 'orden'],
        'tags' => ['etiquetas', 'etiqueta', 'tags'],
    ];

    public function import(string $path): ImportReport
    {
        if (! is_file($path)) {
            throw new RuntimeException("No existe el archivo: {$path}");
        }

        $report = new ImportReport;
        $reader = new Reader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if (Str::ascii(mb_strtolower($sheet->getName())) !== 'catalogo') {
                    continue;
                }

                $this->importSheet($sheet, $report);

                return $report;
            }
        } finally {
            $reader->close();
        }

        throw new RuntimeException('No se encontro la hoja "Catalogo" en el archivo.');
    }

    private function importSheet(\OpenSpout\Reader\XLSX\Sheet $sheet, ImportReport $report): void
    {
        $map = [];
        $rowNumber = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $rowNumber++;
            /** @var array<int, mixed> $cells */
            $cells = $row->toArray();

            if ($rowNumber === 1) {
                $map = $this->mapHeaders($cells);

                continue;
            }

            // Fila totalmente vacia: se ignora en silencio.
            if ($this->isBlankRow($cells)) {
                continue;
            }

            $this->importRow($cells, $map, $rowNumber, $report);
        }
    }

    /**
     * @param  array<int, mixed>  $headerCells
     * @return array<string, int>
     */
    private function mapHeaders(array $headerCells): array
    {
        $normalized = [];
        foreach ($headerCells as $index => $value) {
            $normalized[$index] = Str::ascii(mb_strtolower(trim((string) $value)));
        }

        $map = [];
        foreach (self::COLUMNS as $field => $candidates) {
            foreach ($normalized as $index => $header) {
                if (in_array($header, $candidates, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<string, int>  $map
     */
    private function importRow(array $cells, array $map, int $rowNumber, ImportReport $report): void
    {
        $code = $this->cell($cells, $map, 'code');

        if ($code === '') {
            $report->skipped($rowNumber, 'fila sin ID (code)');

            return;
        }

        $nameEs = $this->cell($cells, $map, 'name_es');
        $description = $this->cell($cells, $map, 'description');
        $url = $this->cell($cells, $map, 'url');
        [$status, $statusBlank] = $this->mapStatus($this->cell($cells, $map, 'status'));
        [$durationEs, $durationEn] = $this->splitBilingual($this->cell($cells, $map, 'duration'));
        [$modalityEs, $modalityEn] = $this->splitBilingual($this->cell($cells, $map, 'modality'));
        $parsed = TagParser::parse($this->cell($cells, $map, 'tags'));

        // Calidad de datos: se importa con lo que haya, pero se marca y reporta.
        $reasons = [];
        if ($nameEs === '') {
            $reasons[] = 'sin nombre';
        }
        if ($description === '') {
            $reasons[] = 'sin descripcion';
        }
        if ($url === '') {
            $reasons[] = 'sin URL';
        }
        if ($parsed['level'] === null && $parsed['goal'] === null && $parsed['profile'] === null) {
            $reasons[] = 'sin etiquetas de nivel/meta/perfil (no apareceria en el emparejador)';
        }
        if ($statusBlank) {
            $reasons[] = 'estado en blanco -> inactiva para revision';
        }

        // Incompleta o con estado en blanco: se fuerza inactiva para revision.
        if ($reasons !== []) {
            $status = 'inactive';
        }

        $program = Program::withTrashed()->where('code', $code)->first();
        $creating = $program === null;

        if ($creating) {
            $program = new Program;
            $program->code = $code;
        }

        if ($program->trashed()) {
            $program->restore();
        }

        $program->name_es = $nameEs;
        // name_en se deja VACIO a proposito: se completa en el panel. credential_en
        // queda como punto de partida disponible para ese trabajo.
        $program->credential_en = $this->cell($cells, $map, 'credential_en') ?: null;
        $program->category_id = $this->resolveCategory($this->cell($cells, $map, 'category'));
        $program->level = $parsed['level'];
        $program->goal = $parsed['goal'];
        $program->profile = $parsed['profile'];
        $program->duration_es = $durationEs;
        $program->duration_en = $durationEn;
        $program->modality_es = $modalityEs;
        $program->modality_en = $modalityEn;
        $program->short_description_es = $description ?: null;
        $program->url = $url;
        $program->status = $status;
        $program->display_order = (int) $this->cell($cells, $map, 'order');
        $program->save();

        // Sincroniza etiquetas libres (idempotente: se reemplazan).
        $program->tags()->delete();
        foreach ($parsed['tags'] as $tag) {
            $program->tags()->create(['tag' => $tag]);
        }

        $creating ? $report->created() : $report->updated();

        if ($reasons !== []) {
            $report->incomplete($code, implode('; ', $reasons));
        }
    }

    private function resolveCategory(string $name): ?int
    {
        if ($name === '') {
            return null;
        }

        $category = ProgramCategory::query()->firstOrCreate(
            ['name_es' => $name],
            ['slug' => Str::slug($name).'-'.Str::lower(Str::random(4))],
        );

        return (int) $category->getKey();
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<string, int>  $map
     */
    private function cell(array $cells, array $map, string $field): string
    {
        if (! array_key_exists($field, $map)) {
            return '';
        }

        $value = $cells[$map[$field]] ?? '';

        return trim((string) $value);
    }

    /**
     * "6 semanas / 6 weeks" -> ['6 semanas', '6 weeks']; "6 semanas" -> ['6 semanas', null].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitBilingual(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, null];
        }

        if (str_contains($raw, '/')) {
            [$es, $en] = array_map('trim', explode('/', $raw, 2));

            return [$es !== '' ? $es : null, $en !== '' ? $en : null];
        }

        return [$raw, null];
    }

    /**
     * @return array{0: string, 1: bool} [status, wasBlank]
     */
    private function mapStatus(string $raw): array
    {
        $v = Str::ascii(mb_strtolower(trim($raw)));

        if ($v === '') {
            return ['inactive', true];
        }

        if (in_array($v, ['si', 'yes', '1', 'true', 'activo', 'x', 'verdadero'], true)) {
            return ['active', false];
        }

        // Cualquier otro valor (no, 0, inactivo, desconocido) -> inactivo por seguridad.
        return ['inactive', false];
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
