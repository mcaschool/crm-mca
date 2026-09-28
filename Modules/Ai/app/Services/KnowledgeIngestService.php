<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Support\KnowledgeTaxonomy;
use Modules\Catalog\Models\Program;

/**
 * Ingesta de archivos .md a la BIBLIOTECA central (pipeline único, sin duplicación): lo usan
 * la pestaña Biblioteca del Centro de Conocimiento y la ficha del asesor.
 *
 * Por archivo: valida (extensión .md, ≤ 512 KB, comentario HTML con Codigo, título "# ",
 * al menos una sección "## "); si falla, se rechaza ESE archivo con un motivo claro y los
 * demás siguen. Los válidos se colocan en biblioteca/{categoria}/ (la línea elegida al subir o,
 * sin ella, la Categoria del comentario normalizada a slug si está en la lista fija; si no,
 * sin_categoria), reemplazando cualquier archivo
 * previo con el mismo código (sin duplicados en disco). Después, un único syncLibrary().
 *
 * NO asigna a agentes: quien llama decide (la ficha asigna al agente; Biblioteca no).
 */
class KnowledgeIngestService
{
    public const MAX_BYTES = 512 * 1024; // 512 KB por archivo

    public function __construct(private readonly KnowledgeSyncService $sync) {}

    /**
     * $classification (opcional, Bloque 4a): tipo + línea (+ programa) elegidos en el espacio
     * de subida de la Biblioteca. Si llega, se valida ANTES de tocar nada, los archivos van a
     * biblioteca/{linea}/ y cada fuente ingerida queda etiquetada con ese tipo/línea/programa.
     * Sin él (ficha del asesor), el comportamiento es el previo: la línea sale del .md.
     *
     * @param  array<int, mixed>  $files
     * @param  array{type: string, line: string, program_id?: ?int}|null  $classification
     * @return array{results: array<int, array{file: string, result: string, reason: string}>, codes: array<int, string>}
     *
     * @throws InvalidArgumentException si la clasificación no es válida
     */
    public function ingest(array $files, ?array $classification = null): array
    {
        if ($classification !== null) {
            $classification = $this->validateClassification($classification);
        }

        $results = [];
        /** @var array<int, array{code: string, category: string, filename: string, doc: UploadedFile}> $valid */
        $valid = [];

        foreach ($files as $doc) {
            if (! $doc instanceof UploadedFile) {
                continue;
            }
            $original = (string) $doc->getClientOriginalName();

            if (strtolower((string) pathinfo($original, PATHINFO_EXTENSION)) !== 'md') {
                $results[] = ['file' => $original, 'result' => 'Rechazado', 'reason' => 'No es un archivo .md.'];

                continue;
            }
            if ((int) $doc->getSize() > self::MAX_BYTES) {
                $results[] = ['file' => $original, 'result' => 'Rechazado', 'reason' => 'Supera el límite de 512 KB.'];

                continue;
            }

            $raw = (string) file_get_contents((string) $doc->getRealPath());
            $info = $this->sync->inspect($raw, $original);

            if (! $info['has_code_meta']) {
                $results[] = ['file' => $original, 'result' => 'Rechazado', 'reason' => 'Falta el comentario HTML con "Codigo".'];

                continue;
            }
            if (! $info['has_title']) {
                $results[] = ['file' => $original, 'result' => 'Rechazado', 'reason' => 'Falta el título "# ".'];

                continue;
            }
            if ($info['sections'] < 1) {
                $results[] = ['file' => $original, 'result' => 'Rechazado', 'reason' => 'Falta al menos una sección "## ".'];

                continue;
            }

            $category = $info['category'] !== null ? $this->sync->normalizeCategory($info['category']) : null;
            $category = KnowledgeTaxonomy::isLine($category) ? $category : null;

            // Con línea elegida: el .md no puede declarar OTRA línea válida (el meta tiene
            // precedencia en el sync y la acabaría cambiando). Sin línea o con texto libre, vale.
            if ($classification !== null && $category !== null && $category !== $classification['line']) {
                $results[] = ['file' => $original, 'result' => 'Rechazado', 'reason' => 'Su «Categoria» ('.KnowledgeTaxonomy::lineLabel($category).') no coincide con la línea elegida ('.KnowledgeTaxonomy::lineLabel($classification['line']).').'];

                continue;
            }
            if ($classification !== null) {
                $category = $classification['line'];
            }

            $valid[] = [
                'code' => (string) $info['code'],
                'category' => $category ?? KnowledgeAssignmentService::NO_CATEGORY,
                'filename' => $this->sanitizeFilename($original),
                'doc' => $doc,
            ];
        }

        // Guardado: se elimina cualquier archivo previo con el MISMO código (evita duplicados
        // en disco aunque cambie el nombre) y se coloca el nuevo en biblioteca/{categoria}/.
        foreach ($valid as $item) {
            $this->sync->deleteFilesForCode($item['code']);
            $item['doc']->storeAs('biblioteca/'.$item['category'], $item['filename'], 'knowledge');
        }

        // Un solo syncLibrary tras colocar todos; el resultado se mapea por código.
        $actionByCode = [];
        if ($valid !== []) {
            foreach ($this->sync->syncLibrary()['files'] as $f) {
                $actionByCode[$f['code']] = $f['action'];
            }
        }

        // Etiquetado con la clasificación elegida (el sync solo fija el tipo al crear).
        if ($classification !== null && $valid !== []) {
            KnowledgeSource::query()
                ->whereIn('code', array_column($valid, 'code'))
                ->get()
                ->each(function (KnowledgeSource $source) use ($classification): void {
                    $source->type = $classification['type'];
                    $source->category = $classification['line'];
                    $source->program_id = $classification['program_id'];
                    $source->save();
                });
        }

        $codes = [];
        foreach ($valid as $item) {
            $action = $actionByCode[$item['code']] ?? 'updated';
            $codes[] = $item['code'];
            $results[] = [
                'file' => $item['filename'],
                'result' => $action === 'created' ? 'Nuevo' : 'Actualizado',
                'reason' => 'Código '.$item['code'].' · categoría '.$item['category'],
            ];
        }

        return ['results' => $results, 'codes' => $codes];
    }

    /**
     * Reglas de la clasificación: tipo y línea de la lista fija; Programa Académico exige un
     * programa ACTIVO del catálogo (no borrado) y no admite la línea institucional; Base de
     * Conocimiento no admite programa.
     *
     * @param  array{type: string, line: string, program_id?: ?int}  $classification
     * @return array{type: string, line: string, program_id: ?int}
     */
    private function validateClassification(array $classification): array
    {
        $type = $classification['type'];
        $line = $classification['line'];
        $programId = $classification['program_id'] ?? null;

        if (! KnowledgeTaxonomy::isType($type)) {
            throw new InvalidArgumentException("Tipo de conocimiento no válido: {$type}.");
        }
        if (! KnowledgeTaxonomy::isLine($line)) {
            throw new InvalidArgumentException("Línea no válida: {$line}.");
        }

        if ($type === KnowledgeTaxonomy::TYPE_KNOWLEDGE) {
            if ($programId !== null) {
                throw new InvalidArgumentException('La Base de Conocimiento no admite programa.');
            }

            return ['type' => $type, 'line' => $line, 'program_id' => null];
        }

        if (! array_key_exists($line, KnowledgeTaxonomy::programLines())) {
            throw new InvalidArgumentException("La línea {$line} no admite Programa Académico.");
        }
        if ($programId === null || ! Program::query()->whereKey($programId)->where('status', 'active')->exists()) {
            throw new InvalidArgumentException('Programa Académico exige un programa activo del catálogo.');
        }

        return ['type' => $type, 'line' => $line, 'program_id' => $programId];
    }

    private function sanitizeFilename(string $original): string
    {
        $base = (string) pathinfo($original, PATHINFO_FILENAME);
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);

        return ($safe !== '' ? $safe : 'kb').'.md';
    }
}
