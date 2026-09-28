<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ai\Models\KnowledgeSource;

/**
 * Sincroniza archivos .md del disco 'knowledge' con la tabla knowledge_sources (biblioteca
 * central del Centro de Conocimiento). Idempotente: upsert por (institution_id, code) — el
 * código identifica la fuente dentro de la institución, sin depender del bot.
 *
 * El código, idioma, categoría y prioridad se leen de un comentario HTML de metadatos
 * (<!-- Codigo: X · Idioma: es · Categoria: ... · Prioridad: N -->); el nombre, del primer
 * "# ". Las barreras de conducta NO viven aquí (config/crm.php): estos archivos son HECHOS.
 *
 * Dos entradas:
 *  - sync($botId, $folder): flujo LEGADO por asesor (sube a su carpeta). Upsert en la
 *    biblioteca + asigna la fuente a ESE bot en el pivote (sin pisar el bot_id existente).
 *  - syncLibrary(): biblioteca central en 'biblioteca/{categoria}/*.md' (sin bot; la
 *    asignación a bots se hace aparte con KnowledgeAssignmentService).
 */
class KnowledgeSyncService
{
    /**
     * Flujo LEGADO (por asesor): upsert por code + asignación al bot en el pivote. NO
     * sobrescribe el bot_id de una fuente existente (Precisión A).
     *
     * @return array{created: int, updated: int, skipped: int, files: array<int, array{file: string, code: string, action: string, language: string}>}
     */
    public function sync(int $botId, string $subfolder = ''): array
    {
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

            // Upsert por CODE (la unicidad es institution_id+code; el scope acota institución).
            $existing = KnowledgeSource::query()->where('code', $parsed['code'])->first();

            $source = $existing ?? new KnowledgeSource;
            // Precisión A: si la fuente no existe, se crea atribuida a este bot (visible en la
            // UI actual). Si ya existe (aunque sea de otro bot), NO se toca su bot_id.
            if ($existing === null) {
                $source->bot_id = $botId;
            }
            $source->code = $parsed['code'];
            $source->source_file = basename($file);
            $source->name = $parsed['name'];
            $source->type = 'general';
            $source->category = $parsed['category'];
            $source->priority = $parsed['priority'];
            if ($existing === null) {
                $source->status = 'active'; // fuentes existentes conservan su estado
            }
            $source->{'content_'.$parsed['language']} = $parsed['content'];
            $source->last_synced_at = now();
            $source->save();

            // Asignación al bot en el pivote (idempotente), activa.
            $source->bots()->syncWithoutDetaching([$botId => ['is_active' => true]]);

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
     * Biblioteca CENTRAL: recorre 'biblioteca/{categoria}/*.md' y hace upsert por
     * (institution_id, code). NO asigna a bots (eso es KnowledgeAssignmentService). La
     * categoría se resuelve por precedencia (Precisión B): meta "Categoria" → carpeta →
     * null, normalizada a slug en minúsculas sin acentos.
     *
     * @return array{created: int, updated: int, skipped: int, files: array<int, array{file: string, code: string, action: string, category: ?string}>}
     */
    public function syncLibrary(): array
    {
        $disk = Storage::disk('knowledge');

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $files = [];

        foreach ($disk->allFiles('biblioteca') as $file) {
            if (! Str::endsWith(strtolower($file), '.md')) {
                continue;
            }

            $raw = (string) $disk->get($file);
            if (trim($raw) === '') {
                $skipped++;
                $files[] = ['file' => basename($file), 'code' => '', 'action' => 'skipped', 'category' => null];

                continue;
            }

            $parsed = $this->parse($raw, basename($file));
            // Precedencia de categoría: meta Categoria → carpeta biblioteca/{categoria}/ → null.
            $category = $parsed['category'] ?? $this->folderCategory($file);
            $category = $category !== null ? $this->normalizeCategory($category) : null;

            $existing = KnowledgeSource::query()->where('code', $parsed['code'])->first();

            $source = $existing ?? new KnowledgeSource;
            $source->code = $parsed['code'];
            $source->source_file = basename($file);
            $source->name = $parsed['name'];
            $source->type = 'general';
            $source->category = $category;
            $source->priority = $parsed['priority'];
            if ($existing === null) {
                $source->status = 'active';
            }
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

            $files[] = ['file' => basename($file), 'code' => $parsed['code'], 'action' => $action, 'category' => $category];
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'files' => $files];
    }

    /**
     * ANTI-RESURRECCIÓN: elimina del disco 'knowledge' TODOS los .md cuyo código (parseado
     * igual que en el upsert: meta Codigo/Código o, en su defecto, el nombre del archivo)
     * coincida EXACTAMENTE con $code. Recorre también las carpetas legadas de asesor, de modo
     * que ni syncLibrary() ni el sync legado puedan recrear la fuente borrada. Registra cada
     * archivo eliminado (ruta + código) y devuelve el conteo.
     */
    public function deleteFilesForCode(string $code): int
    {
        $disk = Storage::disk('knowledge');
        $deleted = 0;

        foreach ($disk->allFiles() as $file) {
            if (! Str::endsWith(strtolower($file), '.md')) {
                continue;
            }
            $raw = (string) $disk->get($file);
            if (trim($raw) === '') {
                continue;
            }
            if ($this->parse($raw, basename($file))['code'] === $code) {
                $disk->delete($file);
                Log::info('knowledge.file_deleted', ['path' => $file, 'code' => $code]);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Inspecciona un .md subido para VALIDARLO antes de guardarlo. Devuelve los metadatos y
     * las banderas de validez (comentario con Codigo, título "# ", al menos una sección "## ").
     *
     * @return array{code: ?string, category: ?string, name: string, priority: int, language: string, has_code_meta: bool, has_title: bool, sections: int}
     */
    public function inspect(string $raw, string $filename): array
    {
        $codeMeta = $this->metaValue($raw, 'Codigo') ?? $this->metaValue($raw, 'Código');
        $hasTitle = preg_match('/^\#\s+.+$/m', $raw) === 1;
        $sections = preg_match_all('/^\#\#\s+.+$/m', $raw);
        $parsed = $this->parse($raw, $filename);

        return [
            'code' => $codeMeta !== null && $codeMeta !== '' ? $codeMeta : null,
            'category' => $parsed['category'],
            'name' => $parsed['name'],
            'priority' => $parsed['priority'],
            'language' => $parsed['language'],
            'has_code_meta' => $codeMeta !== null && $codeMeta !== '',
            'has_title' => $hasTitle,
            'sections' => is_int($sections) ? $sections : 0,
        ];
    }

    /** Primer segmento de carpeta bajo 'biblioteca/' (la categoría), o null si está en la raíz. */
    private function folderCategory(string $path): ?string
    {
        $relative = ltrim(Str::after($path, 'biblioteca'), '/\\');
        $segments = preg_split('#[/\\\\]#', $relative) ?: [];

        // Debe haber al menos carpeta + archivo para que el primer segmento sea categoría.
        return count($segments) >= 2 && $segments[0] !== '' ? $segments[0] : null;
    }

    /** slug en minúsculas, sin acentos, separado por "_" (ej. "Programas Ejecutivos" → programas_ejecutivos). */
    public function normalizeCategory(string $category): ?string
    {
        $slug = Str::slug(trim($category), '_');

        return $slug !== '' ? $slug : null;
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

        $name = null;
        if (preg_match('/^\#\s+(.+)$/m', $raw, $m) === 1) {
            $name = trim($m[1]);
        }

        return [
            'code' => $code !== null && $code !== '' ? $code : Str::upper(Str::slug(pathinfo($filename, PATHINFO_FILENAME), '_')),
            'name' => $name ?? pathinfo($filename, PATHINFO_FILENAME),
            'language' => $language,
            'category' => $category !== null && $category !== '' ? $category : null,
            'priority' => $priorityRaw !== null && is_numeric($priorityRaw) ? (int) $priorityRaw : 0,
            'content' => trim($raw),
        ];
    }

    /**
     * Lee "Clave: valor" dentro de un comentario HTML de metadatos (campos separados por
     * · o |). Insensible a acentos en la clave buscada.
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
