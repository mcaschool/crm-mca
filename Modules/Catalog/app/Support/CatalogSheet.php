<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Generator;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet;
use RuntimeException;

/**
 * Lectura ÚNICA del Excel del catálogo (hoja «Catalogo»), compartida por:
 *  - CatalogImporter: programas, áreas, etiquetas y campos del recomendador;
 *  - MicrocredentialKnowledgeImporter (Ai): una fuente de conocimiento por microcredencial.
 *
 * Mapea las cabeceras (minúsculas, sin acentos) a campos y entrega cada fila no vacía como
 * campo => texto recortado. El Excel es solo una fuente de IMPORTACIÓN: nunca se lee durante
 * las conversaciones.
 */
final class CatalogSheet
{
    /** field => posibles cabeceras normalizadas (minusculas, sin acentos). */
    public const COLUMNS = [
        'code' => ['id'],
        // idnumber del curso en Moodle: enlaza el programa con los leads InCompany (n8n).
        'course_idnumber' => ['idnumber', 'course_idnumber', 'id moodle', 'idnumber moodle', 'id number'],
        'name_es' => ['nombre del programa', 'nombre'],
        'credential_en' => ['microcredencial que otorga', 'microcredencial', 'credencial'],
        'category' => ['area'],
        // Línea de formación (programs.line): «Microcredencial», «Programa Ejecutivo», …
        'line' => ['tipo', 'linea', 'linea de formacion'],
        'duration' => ['duracion'],
        'modality' => ['modalidad'],
        'description' => ['descripcion'],
        // Solo para el conocimiento académico (no la usa el recomendador).
        'learnings' => ['aprendizajes'],
        'url' => ['url'],
        'status' => ['activo', 'estado'],
        'order' => ['peso', 'orden'],
        'tags' => ['etiquetas', 'etiqueta', 'tags'],
    ];

    /**
     * Filas de datos de la hoja «Catalogo» (sin la cabecera ni las filas totalmente vacías).
     *
     * @return Generator<int, array{row: int, fields: array<string, string>}>
     *
     * @throws RuntimeException si no existe el archivo o no tiene la hoja «Catalogo»
     */
    public static function rows(string $path): Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException("No existe el archivo: {$path}");
        }

        $reader = new Reader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if (Str::ascii(mb_strtolower($sheet->getName())) !== 'catalogo') {
                    continue;
                }

                yield from self::sheetRows($sheet);

                return;
            }
        } finally {
            $reader->close();
        }

        throw new RuntimeException('No se encontro la hoja "Catalogo" en el archivo.');
    }

    /**
     * "6 semanas / 6 weeks" -> ['6 semanas', '6 weeks']; "6 semanas" -> ['6 semanas', null].
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function splitBilingual(string $raw): array
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

    /** @return Generator<int, array{row: int, fields: array<string, string>}> */
    private static function sheetRows(Sheet $sheet): Generator
    {
        $map = [];
        $rowNumber = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $rowNumber++;
            /** @var array<int, mixed> $cells */
            $cells = $row->toArray();

            if ($rowNumber === 1) {
                $map = self::mapHeaders($cells);

                continue;
            }

            // Fila totalmente vacia: se ignora en silencio.
            if (self::isBlankRow($cells)) {
                continue;
            }

            $fields = [];
            foreach ($map as $field => $index) {
                $fields[$field] = trim((string) ($cells[$index] ?? ''));
            }

            yield ['row' => $rowNumber, 'fields' => $fields];
        }
    }

    /**
     * @param  array<int, mixed>  $headerCells
     * @return array<string, int>
     */
    private static function mapHeaders(array $headerCells): array
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

    /** @param  array<int, mixed>  $cells */
    private static function isBlankRow(array $cells): bool
    {
        foreach ($cells as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
