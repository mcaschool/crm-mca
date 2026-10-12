<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Support\KnowledgeTaxonomy;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Support\CatalogSheet;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;

/**
 * Conocimiento académico de las MICROCREDENCIALES desde el mismo Excel del catálogo (hoja
 * «Catalogo», columna «Aprendizajes» incluida): una fuente «programa_academico» por programa,
 * con el mismo modelo que las demás líneas. Es una IMPORTACIÓN explícita (comando
 * catalog:import-knowledge): durante las conversaciones nadie lee el Excel; Celia recupera de
 * knowledge_sources y el recomendador sigue con programs / program_tags / bot_program.
 *
 * Persistencia por el pipeline de la Biblioteca: cada ficha es un .md en
 * biblioteca/microcredenciales/institucion-{id}/{CODIGO}.md (KnowledgeIngestService::ingestDocuments:
 * validación, «Programa: MC-xxx», upsert por código, syncLibrary y clasificación). Idempotente: si
 * el .md y su clasificación ya coinciden, la fila queda «sin cambios» y no se escribe nada.
 *
 * Aislamiento (el disco de conocimiento es compartido por la instalación): cada ficha declara
 * «Institucion: {id}» y vive en la carpeta de su institución; KnowledgeSyncService no la considera
 * candidata para ninguna otra (sync, búsqueda/borrado por código). Dos instituciones con el mismo
 * código nunca comparten archivo, contenido, prioridad, estado ni asignaciones.
 *
 * Reglas de estado (las decisiones manuales de la Biblioteca se respetan):
 *  - programa activo y fuente nueva → se crea ACTIVA;
 *  - programa inactivo → su fuente nunca queda activa (se desactiva si existía; no se crea);
 *  - fuente existente desactivada → se conserva desactivada (se informa), aunque el programa
 *    esté activo: reactivar es una decisión explícita en la Biblioteca;
 *  - una fila que desaparece del Excel no borra nada.
 *
 * Base general de la línea (KB-MC-GENERAL-001): su prioridad se normaliza a 6 (como las bases
 * generales de las demás líneas) EN SU ARCHIVO .md, que además queda marcado con la institución, y
 * después se sincroniza. El archivo se localiza por el source_file de la fuente de ESTA institución
 * y solo se escribe si todo es inequívoco (exactamente ese archivo, dentro de biblioteca/, con ese
 * código, de esta institución o sin dueño y sin otra institución que lo use). Si no, no se toca
 * nada y se informa.
 *
 * Asignación (opcional, a UN asesor): solo se crean, activas, las asignaciones que aún no existen
 * (fichas activas de este Excel + bases generales de la línea). Una asignación pausada no se
 * reactiva. bot_program no se toca.
 */
final class MicrocredentialKnowledgeImporter
{
    public const LINE = 'microcredenciales';

    /** Prioridad de las fichas individuales (convención de las fuentes «programa_academico»). */
    public const PRIORITY = 3;

    public const GENERAL_CODE = 'KB-MC-GENERAL-001';

    /** Prioridad de las bases generales de línea (DA/MMBA/PE-FAQ = 6). */
    public const GENERAL_PRIORITY = 6;

    private const FOLDER = 'biblioteca/'.self::LINE;

    /** Campos obligatorios de la fila => etiqueta para el motivo de rechazo. */
    private const REQUIRED = [
        'name_es' => 'nombre del programa',
        'category' => 'área',
        'credential_en' => 'microcredencial que otorga',
        'duration' => 'duración',
        'modality' => 'modalidad',
        'description' => 'descripción',
        'learnings' => 'aprendizajes',
        'url' => 'URL',
    ];

    public function __construct(
        private readonly KnowledgeIngestService $ingest,
        private readonly KnowledgeSyncService $sync,
        private readonly KnowledgeAssignmentService $assignments,
        private readonly CurrentInstitution $institution,
    ) {}

    /**
     * Requiere la institución activa en el contexto (el scope de los modelos acota a ella).
     *
     * @return array{
     *     dry_run: bool,
     *     created: list<string>, updated: list<string>, unchanged: list<string>,
     *     deactivated: list<string>, kept_inactive: list<string>,
     *     rejected: list<array{row: int, code: string, reason: string}>,
     *     general: array{status: string, path: ?string, from: ?int, to: int},
     *     advisor: ?string, assigned: list<string>, kept_paused: list<string>, already_assigned: list<string>
     * }
     */
    public function import(string $path, ?Bot $advisor = null, bool $dryRun = false): array
    {
        $report = [
            'dry_run' => $dryRun, 'created' => [], 'updated' => [], 'unchanged' => [], 'deactivated' => [],
            'kept_inactive' => [], 'rejected' => [], 'general' => ['status' => 'not_found', 'path' => null, 'from' => null, 'to' => self::GENERAL_PRIORITY],
            'advisor' => $advisor?->slug, 'assigned' => [], 'kept_paused' => [], 'already_assigned' => [],
        ];

        $institutionId = $this->institution->idOrFail('importar el conocimiento de microcredenciales');
        $folder = self::folderFor($institutionId);
        /** @var array<string, array{filename: string, content: string, folder: string}> $documents */
        $documents = [];
        $deactivate = [];
        $excelCodes = [];
        $seen = [];

        foreach (CatalogSheet::rows($path) as $row) {
            $fields = $row['fields'];
            $code = $fields['code'] ?? '';
            $reject = function (string $reason) use (&$report, $row, $code): void {
                $report['rejected'][] = ['row' => $row['row'], 'code' => $code, 'reason' => $reason];
            };

            if ($code === '') {
                $reject(__('fila sin código'));

                continue;
            }
            if (isset($seen[mb_strtolower($code)])) {
                $reject(__('código repetido en el Excel'));

                continue;
            }
            $seen[mb_strtolower($code)] = true;
            if (KnowledgeTaxonomy::lineFromLabel($fields['line'] ?? '') !== self::LINE) {
                $reject(__('no es una microcredencial (Tipo: «:type»)', ['type' => $fields['line'] ?? '']));

                continue;
            }
            $missing = array_values(array_map(fn (string $f): string => self::REQUIRED[$f], array_filter(array_keys(self::REQUIRED), fn (string $f): bool => ($fields[$f] ?? '') === '')));
            if ($missing !== []) {
                $reject(__('faltan campos obligatorios: :fields', ['fields' => implode(', ', $missing)]));

                continue;
            }

            // Programa por código en la institución activa (scope); los borrados no cuentan.
            $program = Program::query()->where('code', $code)->first();
            if ($program === null) {
                $reject(__('el programa :code no existe en el catálogo de esta institución', ['code' => $code]));

                continue;
            }
            if ($program->line !== self::LINE) {
                $reject(__('el programa :code no es de la línea Microcredenciales', ['code' => $code]));

                continue;
            }

            $source = KnowledgeSource::query()->where('code', $program->code)->first();
            if ($program->status !== 'active') {
                // Programa inactivo: su ficha nunca queda recuperable por Celia.
                if ($source === null) {
                    $reject(__('el programa :code está inactivo: no se crea su ficha', ['code' => $code]));
                } elseif ($source->status === 'active') {
                    $deactivate[] = $source;
                    $report['deactivated'][] = (string) $program->code;
                } else {
                    $report['unchanged'][] = (string) $program->code;
                }

                continue;
            }

            $excelCodes[] = (string) $program->code;
            $filename = $program->code.'.md';
            $content = self::document($fields, (string) $program->code, $institutionId);
            $disk = Storage::disk('knowledge');
            $file = self::FOLDER.'/'.$folder.'/'.$filename;
            $same = $source !== null
                && $disk->exists($file)
                && (string) $disk->get($file) === $content
                && $source->type === KnowledgeTaxonomy::TYPE_PROGRAM
                && $source->category === self::LINE
                && (int) $source->program_id === (int) $program->getKey()
                && (int) $source->priority === self::PRIORITY;

            if ($same) {
                $report['unchanged'][] = (string) $program->code;
            } else {
                $documents[(string) $program->code] = ['filename' => $filename, 'content' => $content, 'folder' => $folder];
                $report[$source === null ? 'created' : 'updated'][] = (string) $program->code;
            }
            if ($source !== null && $source->status !== 'active') {
                $report['kept_inactive'][] = (string) $program->code;
            }
        }

        $general = $this->planGeneral($institutionId);
        $report['general'] = ['status' => $general['status'], 'path' => $general['path'], 'from' => $general['from'], 'to' => self::GENERAL_PRIORITY];

        if (! $dryRun) {
            $this->write($documents, $deactivate, $general, $report);
            if ($report['general']['status'] === 'would_normalize') {
                $report['general']['status'] = 'normalized';
            }
        }

        if ($advisor !== null) {
            $this->assign($advisor, $excelCodes, $report, $dryRun);
        }

        return $report;
    }

    /**
     * Ficha en Markdown SOLO con lo que trae el Excel (sin traducciones, precios ni requisitos
     * inventados, sin las etiquetas internas). Cada sección nombra el programa: la búsqueda trocea
     * por «## » y cada fragmento debe decir a qué programa pertenece.
     *
     * «Institucion» marca la institución dueña: ninguna otra la considera candidata.
     *
     * @param  array<string, string>  $fields
     */
    public static function document(array $fields, string $code, int $institutionId): string
    {
        $name = self::oneLine($fields['name_es']);
        [$duration] = CatalogSheet::splitBilingual($fields['duration']);
        [$modality] = CatalogSheet::splitBilingual($fields['modality']);

        $lines = [
            '<!-- Codigo: '.$code.' · Programa: '.$code.' · Idioma: es · Categoria: '.self::LINE.' · Prioridad: '.self::PRIORITY.' · '.KnowledgeSyncService::INSTITUTION_META.': '.$institutionId.' -->',
            '# '.$name.' — Microcredencial',
            '',
            '## Descripción de '.$name,
            self::text($fields['description']),
            '',
            '## Qué aprenderás en '.$name,
            self::bullets($fields['learnings']),
            '',
            '## Información académica de '.$name,
            '- Área: '.self::oneLine($fields['category']),
            '- Microcredencial que otorga: '.self::oneLine($fields['credential_en']),
            '- Duración: '.self::oneLine((string) $duration),
            '- Modalidad: '.self::oneLine((string) $modality),
            '',
            '## Página oficial de '.$name,
            trim($fields['url']),
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * Aprendizajes del Excel → viñetas Markdown, completos y en orden: cada línea no vacía es un
     * elemento; se quita solo la viñeta de origen (•, ·, ▪, ◦, ‣, -, *, –), nunca el texto.
     */
    public static function bullets(string $raw): string
    {
        $items = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*[•·▪◦‣∙\-\*–]\s*/u', '', $line));
            if ($line !== '') {
                $items[] = '- '.$line;
            }
        }

        return implode("\n", $items);
    }

    /** Carpeta (dentro de biblioteca/microcredenciales/) de las fichas de una institución. */
    public static function folderFor(int $institutionId): string
    {
        return 'institucion-'.$institutionId;
    }

    /** Párrafos del Excel tal cual (saltos de línea normalizados, sin líneas en blanco sobrantes). */
    private static function text(string $raw): string
    {
        $raw = (string) preg_replace('/\R/u', "\n", trim($raw));

        return (string) preg_replace("/\n{3,}/", "\n\n", $raw);
    }

    private static function oneLine(string $raw): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $raw));
    }

    /**
     * Base general de ESTA institución. Referencia primaria: el source_file de su fuente. Solo se
     * normaliza si TODO es inequívoco:
     *  - la fuente existe en la institución activa y tiene source_file;
     *  - hay exactamente UN .md candidato (de esta institución o sin dueño) con ese nombre y código;
     *  - está dentro de biblioteca/;
     *  - si no declara institución, ninguna OTRA institución tiene una fuente con ese código y archivo.
     * Normalizar = prioridad 6 y marca «Institucion» de la activa (desde entonces es solo suyo).
     *
     * @return array{status: string, path: ?string, from: ?int, content: ?string}
     */
    private function planGeneral(int $institutionId): array
    {
        $source = KnowledgeSource::query()->where('code', self::GENERAL_CODE)->first();
        if ($source === null) {
            return ['status' => 'not_found', 'path' => null, 'from' => null, 'content' => null];
        }
        $sourceFile = trim((string) $source->source_file);
        if ($sourceFile === '') {
            return ['status' => 'no_source_file', 'path' => null, 'from' => (int) $source->priority, 'content' => null];
        }

        $files = array_values(array_filter($this->sync->filesForCode(self::GENERAL_CODE), fn (string $f): bool => basename($f) === $sourceFile));
        if ($files === []) {
            return ['status' => 'not_found', 'path' => null, 'from' => (int) $source->priority, 'content' => null];
        }
        if (count($files) > 1) {
            return ['status' => 'ambiguous', 'path' => implode(', ', $files), 'from' => (int) $source->priority, 'content' => null];
        }
        $path = $files[0];
        if (! str_starts_with(str_replace('\\', '/', $path), 'biblioteca/')) {
            return ['status' => 'outside_library', 'path' => $path, 'from' => (int) $source->priority, 'content' => null];
        }

        $raw = (string) Storage::disk('knowledge')->get($path);
        $info = $this->sync->inspect($raw, basename($path));
        if ($info['code'] !== self::GENERAL_CODE) {
            return ['status' => 'ambiguous', 'path' => $path, 'from' => (int) $source->priority, 'content' => null];
        }
        $owner = $this->sync->institutionOf($raw);
        if ($owner === null) {
            // Archivo antiguo sin dueño: solo si ninguna otra institución lo usa.
            $shared = $this->institution->runGlobally(fn (): bool => KnowledgeSource::query()
                ->where('code', self::GENERAL_CODE)->where('source_file', $sourceFile)
                ->where('institution_id', '!=', $institutionId)->exists());
            if ($shared) {
                return ['status' => 'shared', 'path' => $path, 'from' => (int) $source->priority, 'content' => null];
            }
        } elseif ($owner !== (string) $institutionId) {
            return ['status' => 'ambiguous', 'path' => $path, 'from' => (int) $source->priority, 'content' => null];
        }

        $normalized = $this->sync->withPriority($raw, self::GENERAL_PRIORITY);
        $normalized = $normalized !== null ? $this->sync->withInstitution($normalized, $institutionId) : null;
        if ($normalized === null) {
            return ['status' => 'no_metadata', 'path' => $path, 'from' => (int) $source->priority, 'content' => null];
        }
        if ($info['priority'] === self::GENERAL_PRIORITY && (int) $source->priority === self::GENERAL_PRIORITY && $owner === (string) $institutionId) {
            return ['status' => 'already', 'path' => $path, 'from' => self::GENERAL_PRIORITY, 'content' => null];
        }

        return ['status' => 'would_normalize', 'path' => $path, 'from' => (int) $source->priority, 'content' => $normalized];
    }

    /**
     * @param  array<string, array{filename: string, content: string, folder: string}>  $documents
     * @param  list<KnowledgeSource>  $deactivate
     * @param  array{status: string, path: ?string, from: ?int, content: ?string}  $general
     * @param  array<string, mixed>  $report
     */
    private function write(array $documents, array $deactivate, array $general, array &$report): void
    {
        // 1) Base general: la prioridad se corrige en su ARCHIVO (fuente de verdad) y la recoge el
        //    syncLibrary siguiente (el de la ingesta o, si no hay fichas que escribir, uno propio).
        if ($general['status'] === 'would_normalize' && $general['path'] !== null && $general['content'] !== null) {
            Storage::disk('knowledge')->put($general['path'], $general['content']);
        }

        // 2) Fichas por el pipeline de la Biblioteca (valida, coloca, sincroniza y clasifica).
        if ($documents !== []) {
            $result = $this->ingest->ingestDocuments(array_values($documents), [
                'type' => KnowledgeTaxonomy::TYPE_PROGRAM,
                'line' => self::LINE,
                'auto_program' => true,
            ]);
            foreach ($result['results'] as $r) {
                if ($r['result'] === 'Rechazado') {
                    $code = (string) pathinfo($r['file'], PATHINFO_FILENAME);
                    $report['created'] = array_values(array_diff($report['created'], [$code]));
                    $report['updated'] = array_values(array_diff($report['updated'], [$code]));
                    $report['rejected'][] = ['row' => 0, 'code' => $code, 'reason' => $r['reason']];
                }
            }
        } elseif ($general['status'] === 'would_normalize') {
            $this->sync->syncLibrary();
        }

        // 3) Programas inactivos: su ficha deja de ser recuperable.
        foreach ($deactivate as $source) {
            $source->status = 'inactive';
            $source->save();
        }
    }

    /**
     * Asignación a UN asesor: fichas activas de este Excel + bases generales activas de la línea.
     * Solo se crean (activas) las que no existen; las pausadas se conservan pausadas.
     *
     * @param  list<string>  $excelCodes
     * @param  array<string, mixed>  $report
     */
    private function assign(Bot $advisor, array $excelCodes, array &$report, bool $dryRun): void
    {
        $sources = KnowledgeSource::query()
            ->where('category', self::LINE)
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('type', KnowledgeTaxonomy::TYPE_KNOWLEDGE)
                ->orWhere(fn ($w) => $w->where('type', KnowledgeTaxonomy::TYPE_PROGRAM)->whereIn('code', $excelCodes)))
            ->orderBy('code')
            ->get();
        // Asignaciones actuales del asesor: id de la fuente => activa (en el pivote).
        $pivot = $advisor->knowledgeSources()
            ->pluck('bot_knowledge_source.is_active', 'knowledge_sources.id')
            ->mapWithKeys(fn ($active, $id): array => [(int) $id => (bool) $active]);

        foreach ($sources as $source) {
            $code = (string) $source->code;
            if (! $pivot->has((int) $source->getKey())) {
                if (! $dryRun) {
                    $this->assignments->assignSource($advisor, $source);
                }
                $report['assigned'][] = $code;
            } elseif ($pivot->get((int) $source->getKey()) === false) {
                $report['kept_paused'][] = $code;
            } else {
                $report['already_assigned'][] = $code;
            }
        }

        // En la previsualización, las fichas que se CREARÍAN también se asignarían.
        if ($dryRun) {
            foreach ($report['created'] as $code) {
                if (in_array($code, $excelCodes, true) && ! in_array($code, $report['assigned'], true)) {
                    $report['assigned'][] = $code;
                }
            }
        }
    }
}
