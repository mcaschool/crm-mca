<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Illuminate\Support\Str;
use Modules\Ai\Support\KnowledgeTaxonomy;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Catalog\Support\CatalogSheet;
use Modules\Catalog\Support\TagParser;
use RuntimeException;

/**
 * Importador idempotente del catalogo desde el Excel del cliente (hoja "Catalogo").
 *
 * - Upsert por `code` (MC-001...): re-ejecutar ACTUALIZA, no duplica.
 * - Tolera imperfecciones conocidas: filas incompletas se importan con lo que
 *   haya, se marcan inactivas / para revision y se reportan; nunca falla en seco.
 * - Requiere una institucion activa en el contexto (lo fija el comando).
 *
 * La lectura de la hoja es la compartida (CatalogSheet). La columna "Aprendizajes" NO la usa
 * este importador: alimenta las fuentes de conocimiento de cada microcredencial
 * (MicrocredentialKnowledgeImporter, comando catalog:import-knowledge).
 */
class CatalogImporter
{
    /**
     * @throws RuntimeException si no existe el archivo o no tiene la hoja "Catalogo"
     */
    public function import(string $path): ImportReport
    {
        $report = new ImportReport;

        foreach (CatalogSheet::rows($path) as $row) {
            $this->importRow($row['fields'], $row['row'], $report);
        }

        return $report;
    }

    /** @param  array<string, string>  $fields  campo => texto (CatalogSheet) */
    private function importRow(array $fields, int $rowNumber, ImportReport $report): void
    {
        $code = $this->cell($fields, 'code');

        if ($code === '') {
            $report->skipped($rowNumber, 'fila sin ID (code)');

            return;
        }

        $nameEs = $this->cell($fields, 'name_es');
        $description = $this->cell($fields, 'description');
        $url = $this->cell($fields, 'url');
        [$status, $statusBlank] = $this->mapStatus($this->cell($fields, 'status'));
        [$durationEs, $durationEn] = CatalogSheet::splitBilingual($this->cell($fields, 'duration'));
        [$modalityEs, $modalityEn] = CatalogSheet::splitBilingual($this->cell($fields, 'modality'));
        $parsed = TagParser::parse($this->cell($fields, 'tags'));

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

        // idnumber de Moodle: solo se escribe si viene con valor (no borra el existente).
        $idnumber = $this->cell($fields, 'course_idnumber');
        if ($idnumber !== '') {
            $program->course_idnumber = $idnumber;
        }

        $program->name_es = $nameEs;
        // name_en se deja VACIO a proposito: se completa en el panel. credential_en
        // queda como punto de partida disponible para ese trabajo.
        $program->credential_en = $this->cell($fields, 'credential_en') ?: null;
        // Línea desde «Tipo» (solo si la celda trae algo; vacía = no se toca). Un tipo que no
        // corresponde a ninguna línea de la lista fija se importa con line NULL y se reporta.
        $type = $this->cell($fields, 'line');
        if ($type !== '') {
            $program->line = KnowledgeTaxonomy::lineFromLabel($type);
            if ($program->line === null) {
                $report->unknownType($code, $type);
            }
        }

        // Las áreas solo existen en Microcredenciales: en cualquier otra línea la columna Area
        // se ignora (ni se asigna ni se crea el área; el category_id guardado no se toca).
        if (Program::lineHasAreas($program->line)) {
            $program->category_id = $this->resolveCategory($this->cell($fields, 'category'));
        }
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
        $program->display_order = (int) $this->cell($fields, 'order');
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

    /** @param  array<string, string>  $fields */
    private function cell(array $fields, string $field): string
    {
        return $fields[$field] ?? '';
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
}
