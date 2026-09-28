<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Http\UploadedFile;

/**
 * Ingesta de archivos .md a la BIBLIOTECA central (pipeline único, sin duplicación): lo usan
 * la pestaña Biblioteca del Centro de Conocimiento y la ficha del asesor.
 *
 * Por archivo: valida (extensión .md, ≤ 512 KB, comentario HTML con Codigo, título "# ",
 * al menos una sección "## "); si falla, se rechaza ESE archivo con un motivo claro y los
 * demás siguen. Los válidos se colocan en biblioteca/{categoria}/ (Categoria del comentario
 * normalizada a slug; si falta o queda vacía, sin_categoria), reemplazando cualquier archivo
 * previo con el mismo código (sin duplicados en disco). Después, un único syncLibrary().
 *
 * NO asigna a agentes: quien llama decide (la ficha asigna al agente; Biblioteca no).
 */
class KnowledgeIngestService
{
    public const MAX_BYTES = 512 * 1024; // 512 KB por archivo

    public function __construct(private readonly KnowledgeSyncService $sync) {}

    /**
     * @param  array<int, mixed>  $files
     * @return array{results: array<int, array{file: string, result: string, reason: string}>, codes: array<int, string>}
     */
    public function ingest(array $files): array
    {
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

            $valid[] = [
                'code' => (string) $info['code'],
                'category' => ($category !== null && $category !== '') ? $category : KnowledgeAssignmentService::NO_CATEGORY,
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

    private function sanitizeFilename(string $original): string
    {
        $base = (string) pathinfo($original, PATHINFO_FILENAME);
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);

        return ($safe !== '' ? $safe : 'kb').'.md';
    }
}
