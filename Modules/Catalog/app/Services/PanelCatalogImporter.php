<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramLine;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Importador de catálogo DESDE EL PANEL (Fase 3). Archivo de 3 columnas:
 *   course_id | Nombre del Programa | Categoría (de formación)
 *
 * - Llave de upsert: course_idnumber (Opción A). Existe → ACTUALIZA; no existe → CREA.
 * - "Categoría" = categoría de FORMACIÓN (line_id → program_lines). Si no existe, se crea
 *   sola (idempotente por nombre normalizado). NO toca el eje de áreas (category_id).
 * - `code` (MC-XXX) no es llave ni obligatorio: en altas nuevas queda NULL (no se inventa).
 * - course_id obligatorio por fila; comparación con trim + case-insensitive.
 *
 * analyze() NO escribe nada (para la vista previa); apply() aplica en una transacción.
 */
class PanelCatalogImporter
{
    /** Encabezados aceptados (normalizados a minúscula/sin acentos). */
    private const HEADERS = [
        'course_id' => ['course_id', 'course id', 'moodle course_id', 'moodle course id', 'course_idnumber', 'idnumber', 'id moodle', 'id'],
        'name' => ['nombre del programa', 'nombre', 'name', 'programa'],
        'category' => ['categoria', 'categoria de formacion', 'category', 'linea', 'line'],
    ];

    /**
     * Lee el archivo (xlsx o csv) a filas normalizadas.
     *
     * @return array<int, array{course_id: string, name: string, category: string, row: int}>
     */
    public function parse(string $path, string $extension): array
    {
        $reader = strtolower($extension) === 'csv' ? new CsvReader : new XlsxReader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                return $this->readSheet($sheet);
            }
        } finally {
            $reader->close();
        }

        throw new RuntimeException('El archivo no tiene datos.');
    }

    /**
     * @return array<int, array{course_id: string, name: string, category: string, row: int}>
     */
    private function readSheet(mixed $sheet): array
    {
        $map = [];
        $rowNumber = 0;
        $out = [];

        foreach ($sheet->getRowIterator() as $row) {
            $rowNumber++;
            $cells = $row->toArray();

            if ($rowNumber === 1) {
                $map = $this->mapHeaders($cells);

                continue;
            }

            $courseId = $this->cell($cells, $map, 'course_id');
            $name = $this->cell($cells, $map, 'name');
            $category = $this->cell($cells, $map, 'category');

            // Fila totalmente vacía: se ignora en silencio.
            if ($courseId === '' && $name === '' && $category === '') {
                continue;
            }

            $out[] = ['course_id' => $courseId, 'name' => $name, 'category' => $category, 'row' => $rowNumber];
        }

        return $out;
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
        foreach (self::HEADERS as $field => $candidates) {
            foreach ($candidates as $candidate) {
                $index = array_search($candidate, $normalized, true);
                if ($index !== false) {
                    $map[$field] = (int) $index;
                    break;
                }
            }
        }

        foreach (['course_id', 'name', 'category'] as $required) {
            if (! isset($map[$required])) {
                throw new RuntimeException('Falta la columna requerida: '.$required.'. El archivo debe tener course_id, Nombre y Categoría.');
            }
        }

        return $map;
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<string, int>  $map
     */
    private function cell(array $cells, array $map, string $field): string
    {
        $index = $map[$field] ?? null;

        return $index === null ? '' : trim((string) ($cells[$index] ?? ''));
    }

    /**
     * Construye el plan (vista previa) SIN escribir nada.
     *
     * @param  array<int, array{course_id: string, name: string, category: string, row: int}>  $rows
     * @return array{creates: array<int, array<string,string>>, updates: array<int, array<string,mixed>>, new_categories: array<int, string>, errors: array<int, array<string,mixed>>, counts: array<string,int>}
     */
    public function analyze(array $rows): array
    {
        $creates = [];
        $updates = [];
        $errors = [];
        $newCategories = [];
        $seen = [];

        $existingLineNames = ProgramLine::query()->pluck('name_es')
            ->mapWithKeys(fn (string $n) => [$this->norm($n) => true])->all();

        foreach ($rows as $r) {
            $cid = $r['course_id'];
            $name = $r['name'];
            $cat = $r['category'];

            if ($cid === '') {
                $errors[] = ['row' => $r['row'], 'course_id' => '', 'reason' => 'course_id vacío'];

                continue;
            }
            if ($name === '') {
                $errors[] = ['row' => $r['row'], 'course_id' => $cid, 'reason' => 'nombre vacío'];

                continue;
            }
            if ($cat === '') {
                $errors[] = ['row' => $r['row'], 'course_id' => $cid, 'reason' => 'categoría vacía'];

                continue;
            }
            if (isset($seen[$this->norm($cid)])) {
                $errors[] = ['row' => $r['row'], 'course_id' => $cid, 'reason' => 'course_id duplicado en el archivo'];

                continue;
            }
            $seen[$this->norm($cid)] = true;

            // ¿Categoría nueva?
            if (! isset($existingLineNames[$this->norm($cat)]) && ! in_array($cat, $newCategories, true)) {
                $newCategories[] = $cat;
            }

            $program = $this->findProgram($cid);
            if ($program === null) {
                $creates[] = ['course_id' => $cid, 'name' => $name, 'category' => $cat];

                continue;
            }

            $changes = [];
            if ((string) $program->name_es !== $name) {
                $changes['name'] = ['from' => (string) $program->name_es, 'to' => $name];
            }
            $currentCat = optional($program->line)->name_es;
            if ($this->norm((string) $currentCat) !== $this->norm($cat)) {
                $changes['category'] = ['from' => $currentCat ?: '—', 'to' => $cat];
            }
            $updates[] = ['course_id' => $cid, 'name' => $name, 'changes' => $changes];
        }

        return [
            'creates' => $creates,
            'updates' => $updates,
            'new_categories' => $newCategories,
            'errors' => $errors,
            'counts' => [
                'create' => count($creates),
                'update' => count($updates),
                'new_categories' => count($newCategories),
                'error' => count($errors),
            ],
        ];
    }

    /**
     * Aplica el upsert en una transacción. Reusa las MISMAS reglas de validez que analyze
     * (salta filas con error). Idempotente. NO toca category_id.
     *
     * @param  array<int, array{course_id: string, name: string, category: string, row: int}>  $rows
     * @return array{created: int, updated: int, new_categories: int, skipped: int}
     */
    public function apply(array $rows): array
    {
        return DB::transaction(function () use ($rows): array {
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $newCats = 0;
            $seen = [];
            /** @var array<string, ProgramLine> $lineCache */
            $lineCache = [];

            foreach ($rows as $r) {
                $cid = $r['course_id'];
                $name = $r['name'];
                $cat = $r['category'];

                if ($cid === '' || $name === '' || $cat === '' || isset($seen[$this->norm($cid)])) {
                    $skipped++;

                    continue;
                }
                $seen[$this->norm($cid)] = true;

                // Resolver/crear la categoría de formación (por nombre normalizado).
                $key = $this->norm($cat);
                if (! isset($lineCache[$key])) {
                    $line = ProgramLine::query()->whereRaw('LOWER(name_es) = ?', [$this->norm($cat)])->first();
                    if ($line === null) {
                        $line = ProgramLine::query()->create([
                            'name_es' => $cat,
                            'slug' => Str::slug($cat).'-'.Str::lower(Str::random(4)),
                        ]);
                        $newCats++;
                    }
                    $lineCache[$key] = $line;
                }
                $line = $lineCache[$key];

                $program = $this->findProgram($cid);
                if ($program === null) {
                    $program = new Program;
                    $program->course_idnumber = $cid;
                    $program->code = null;      // no se inventa MC-XXX
                    $program->url = '';         // se completa después (F4)
                    $program->status = 'active';
                    $program->name_es = $name;
                    $program->line_id = $line->getKey();
                    $program->save();
                    $created++;
                } else {
                    // Solo nombre y categoría de formación; NO se toca category_id (área).
                    $program->name_es = $name;
                    $program->line_id = $line->getKey();
                    $program->save();
                    $updated++;
                }
            }

            return ['created' => $created, 'updated' => $updated, 'new_categories' => $newCats, 'skipped' => $skipped];
        });
    }

    /** Busca un programa por course_idnumber (trim + case-insensitive). */
    private function findProgram(string $courseId): ?Program
    {
        return Program::query()->whereRaw('LOWER(course_idnumber) = ?', [$this->norm($courseId)])->first();
    }

    private function norm(string $s): string
    {
        return mb_strtolower(trim($s));
    }
}
