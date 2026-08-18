<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ai\Models\KnowledgeSource;

/**
 * Sincroniza la carpeta de conocimiento (storage/app/knowledge/*.md) con la tabla
 * knowledge_sources. Idempotente: hace upsert por (bot_id, code). El codigo y el
 * idioma se leen de un comentario del .md (<!-- Codigo: X · Idioma: es -->); si
 * falta el codigo, se usa el nombre del archivo. Las barreras de conducta NO
 * viven aqui (estan en config/crm.php): estos archivos son solo HECHOS.
 */
class KnowledgeSyncService
{
    /**
     * @param  string  $subfolder  carpeta del asesor dentro del disco (p. ej. su slug).
     *                             Vacio = raiz del disco (compatibilidad).
     * @return array{created: int, updated: int, skipped: int, files: array<int, array{file: string, code: string, action: string, language: string}>}
     */
    public function sync(int $botId, string $subfolder = ''): array
    {
        // Disco dedicado: su raiz es storage/app/knowledge (ver config/filesystems).
        // Cada asesor tiene su propia subcarpeta (aislada para el futuro multi-asesor).
        $disk = Storage::disk('knowledge');

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $files = [];

        foreach ($disk->files($subfolder) as $file) {
            if (! Str::endsWith(strtolower($file), '.md')) {
                continue;
            }

            $raw = (string) $disk->get($file);
            if (trim($raw) === '') {
                $skipped++;
                $files[] = ['file' => basename($file), 'code' => '', 'action' => 'skipped', 'language' => ''];

                continue;
            }

            $parsed = $this->parse($raw, basename($file));

            /** @var KnowledgeSource|null $existing */
            $existing = KnowledgeSource::query()
                ->where('bot_id', $botId)
                ->where('code', $parsed['code'])
                ->first();

            $source = $existing ?? new KnowledgeSource;
            $source->bot_id = $botId;
            $source->code = $parsed['code'];
            $source->source_file = basename($file);
            $source->name = $parsed['name'];
            $source->type = 'general';
            $source->category = $parsed['category'];
            $source->priority = $parsed['priority'];
            $source->status = 'active';
            $source->{'content_'.$parsed['language']} = $parsed['content'];
            $source->last_synced_at = now();
            $source->save();

            if ($existing === null) {
                $created++;
                $action = 'created';
            } else {
                $updated++;
                $action = 'updated';
            }

            $files[] = ['file' => basename($file), 'code' => $parsed['code'], 'action' => $action, 'language' => $parsed['language']];
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'files' => $files];
    }

    /**
     * @return array{code: string, name: string, language: string, category: ?string, priority: int, content: string}
     */
    private function parse(string $raw, string $filename): array
    {
        $code = $this->metaValue($raw, 'Codigo') ?? $this->metaValue($raw, 'Código');
        $language = strtolower((string) ($this->metaValue($raw, 'Idioma') ?? 'es'));
        $language = in_array($language, ['es', 'en'], true) ? $language : 'es';
        $category = $this->metaValue($raw, 'Categoria') ?? $this->metaValue($raw, 'Categoría');
        $priorityRaw = $this->metaValue($raw, 'Prioridad');

        // Nombre = primer encabezado "# " del documento; si falta, el archivo.
        $name = null;
        if (preg_match('/^\#\s+(.+)$/m', $raw, $m) === 1) {
            $name = trim($m[1]);
        }

        return [
            'code' => $code !== null && $code !== '' ? $code : Str::upper(Str::slug(pathinfo($filename, PATHINFO_FILENAME), '_')),
            'name' => $name ?? pathinfo($filename, PATHINFO_FILENAME),
            'language' => $language,
            'category' => $category !== '' ? $category : null,
            'priority' => $priorityRaw !== null && is_numeric($priorityRaw) ? (int) $priorityRaw : 0,
            'content' => trim($raw),
        ];
    }

    /**
     * Lee "Clave: valor" dentro de un comentario HTML de metadatos (campos
     * separados por · o |). Insensible a acentos en la clave buscada.
     */
    private function metaValue(string $raw, string $key): ?string
    {
        $normalizedKey = Str::of($key)->lower()->ascii()->toString();

        if (preg_match('/<!--(.+?)-->/s', $raw, $block) !== 1) {
            return null;
        }

        foreach (preg_split('/[·|]/u', $block[1]) ?: [] as $field) {
            $parts = explode(':', $field, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $fieldKey = Str::of($parts[0])->trim()->lower()->ascii()->toString();
            if ($fieldKey === $normalizedKey) {
                return trim($parts[1]);
            }
        }

        return null;
    }
}
