<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Support\KnowledgeTaxonomy;
use Modules\Core\Tenancy\CurrentInstitution;

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
 *
 * AISLAMIENTO: el disco 'knowledge' es uno solo para toda la instalación. Un .md con el campo
 * «Institucion: {id}» en su comentario de metadatos PERTENECE a esa institución: para cualquier
 * otra no es candidato en ninguna operación (sync, syncLibrary, búsqueda y borrado por código).
 * Los .md antiguos sin ese campo se comportan como siempre (compatibilidad).
 */
class KnowledgeSyncService
{
    /** Campo de metadatos con el id de la institución dueña del .md. */
    public const INSTITUTION_META = 'Institucion';

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
            if (! $this->belongsHere($raw)) {
                continue; // de otra institución: ni se lee como candidato
            }
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
            $this->applyTaxonomy($source, $existing === null, [$parsed['category']]);
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
     * categoría (línea) se resuelve por precedencia (Precisión B): meta "Categoria" →
     * carpeta, normalizada a slug y validada contra la lista fija (ver applyTaxonomy()).
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
            if (! $this->belongsHere($raw)) {
                continue; // de otra institución: ni se lee como candidato
            }
            if (trim($raw) === '') {
                $skipped++;
                $files[] = ['file' => basename($file), 'code' => '', 'action' => 'skipped', 'category' => null];

                continue;
            }

            $parsed = $this->parse($raw, basename($file));

            $existing = KnowledgeSource::query()->where('code', $parsed['code'])->first();

            $source = $existing ?? new KnowledgeSource;
            $source->code = $parsed['code'];
            $source->source_file = basename($file);
            $source->name = $parsed['name'];
            // Precedencia de línea: meta Categoria → carpeta biblioteca/{linea}/ (la primera
            // que esté en la lista fija); si ninguna lo está, la fila conserva la suya.
            $this->applyTaxonomy($source, $existing === null, [$parsed['category'], $this->folderCategory($file)]);
            $category = $source->category;
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

        foreach ($this->filesForCode($code) as $file) {
            $disk->delete($file);
            Log::info('knowledge.file_deleted', ['path' => $file, 'code' => $code]);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Rutas (en el disco 'knowledge', incluidas las carpetas legadas) de TODOS los .md de ESTA
     * institución (o antiguos sin institución) cuyo código, parseado igual que en el upsert, es
     * exactamente $code.
     *
     * @return list<string>
     */
    public function filesForCode(string $code): array
    {
        $disk = Storage::disk('knowledge');
        $found = [];

        foreach ($disk->allFiles() as $file) {
            if (! Str::endsWith(strtolower($file), '.md')) {
                continue;
            }
            $raw = (string) $disk->get($file);
            if (trim($raw) !== '' && $this->belongsHere($raw) && $this->parse($raw, basename($file))['code'] === $code) {
                $found[] = $file;
            }
        }

        return $found;
    }

    /**
     * El mismo .md con «Prioridad: $priority» en su comentario de metadatos: reemplaza el valor
     * si el campo existe o lo añade al final del comentario. Null si no hay comentario de
     * metadatos (no se inventa uno). Idempotente.
     */
    public function withPriority(string $raw, int $priority): ?string
    {
        return $this->withMeta($raw, 'Prioridad', (string) $priority);
    }

    /** El mismo .md marcado como de la institución $institutionId (ver INSTITUTION_META). */
    public function withInstitution(string $raw, int $institutionId): ?string
    {
        return $this->withMeta($raw, self::INSTITUTION_META, (string) $institutionId);
    }

    /** Institución dueña del .md (campo «Institucion»), o null si es un .md antiguo sin ella. */
    public function institutionOf(string $raw): ?string
    {
        $owner = $this->metaValue($raw, self::INSTITUTION_META);

        return $owner !== null && $owner !== '' ? $owner : null;
    }

    /**
     * ¿Es candidato para la institución activa? Sí si no declara institución (compatibilidad) o
     * declara exactamente la activa. Sin institución activa, ningún .md marcado lo es.
     */
    public function belongsHere(string $raw): bool
    {
        $owner = $this->institutionOf($raw);
        if ($owner === null) {
            return true;
        }
        $current = app(CurrentInstitution::class)->id();

        return $current !== null && $owner === (string) $current;
    }

    /**
     * Fija «$key: $value» en el comentario de metadatos: reemplaza el valor si el campo existe o lo
     * añade al final. Null si no hay comentario de metadatos (no se inventa uno). Idempotente.
     */
    private function withMeta(string $raw, string $key, string $value): ?string
    {
        if (preg_match('/<!--(.+?)-->/s', $raw, $block, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $wanted = Str::of($key)->lower()->ascii()->toString();
        $fields = preg_split('/([·|])/u', $block[1][0], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $found = false;
        foreach ($fields as $i => $field) {
            $parts = explode(':', $field, 2);
            if (count($parts) === 2 && Str::of($parts[0])->trim()->lower()->ascii()->toString() === $wanted) {
                $fields[$i] = $parts[0].': '.$value.(preg_match('/\s+$/u', $parts[1], $m) === 1 ? $m[0] : '');
                $found = true;
            }
        }
        $inner = $found ? implode('', $fields) : rtrim($block[1][0]).' · '.$key.': '.$value.' ';

        return substr_replace($raw, $inner, $block[1][1], strlen($block[1][0]));
    }

    /**
     * Inspecciona un .md subido para VALIDARLO antes de guardarlo. Devuelve los metadatos y
     * las banderas de validez (comentario con Codigo, título "# ", al menos una sección "## ").
     * «Programa» (opcional) es el código del programa del catálogo para la carga masiva por
     * línea; cualquier otro campo del comentario se ignora (no provoca rechazo).
     *
     * @return array{code: ?string, category: ?string, program: ?string, name: string, priority: int, language: string, has_code_meta: bool, has_title: bool, sections: int}
     */
    public function inspect(string $raw, string $filename): array
    {
        $codeMeta = $this->metaValue($raw, 'Codigo') ?? $this->metaValue($raw, 'Código');
        $programMeta = $this->metaValue($raw, 'Programa');
        $hasTitle = preg_match('/^\#\s+.+$/m', $raw) === 1;
        $sections = preg_match_all('/^\#\#\s+.+$/m', $raw);
        $parsed = $this->parse($raw, $filename);

        return [
            'code' => $codeMeta !== null && $codeMeta !== '' ? $codeMeta : null,
            'category' => $parsed['category'],
            'program' => $programMeta !== null && $programMeta !== '' ? $programMeta : null,
            'name' => $parsed['name'],
            'priority' => $parsed['priority'],
            'language' => $parsed['language'],
            'has_code_meta' => $codeMeta !== null && $codeMeta !== '',
            'has_title' => $hasTitle,
            'sections' => is_int($sections) ? $sections : 0,
        ];
    }

    /**
     * Taxonomía en el upsert (Bloque 4a):
     *  - type: solo se fija al CREAR (base_conocimiento por defecto); al actualizar NUNCA se
     *    sobrescribe, para no pisar el tipo elegido al subir (ni el de filas antiguas).
     *  - category: la primera candidata (ya normalizada) que esté en la lista fija de líneas.
     *    Si ninguna lo está, la fila conserva su valor (null si es nueva): el texto libre ya no
     *    entra como línea.
     *
     * @param  array<int, ?string>  $candidates  en orden de precedencia
     */
    private function applyTaxonomy(KnowledgeSource $source, bool $isNew, array $candidates): void
    {
        if ($isNew) {
            $source->type = KnowledgeTaxonomy::TYPE_KNOWLEDGE;
        }

        foreach ($candidates as $candidate) {
            $line = $candidate !== null ? $this->normalizeCategory($candidate) : null;
            if (KnowledgeTaxonomy::isLine($line)) {
                $source->category = $line;

                return;
            }
        }

        if ($isNew) {
            $source->category = null;
        }
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
