<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Modules\Ai\Support\KnowledgeTaxonomy;
use Modules\Audit\Services\AuditService;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;

/**
 * Lógica CENTRAL de alta manual de programas en el catálogo institucional (tabla programs,
 * modelo Program): la usan el alta individual («+ Añadir programa») y la masiva («Importar
 * programas») del Centro de Conocimiento, y Catálogo → Nuevo/Editar programa reutiliza sus
 * reglas comunes (formato de código y URL, duplicados, normalización del nombre, auditoría).
 * No hay catálogo paralelo: lo creado aquí es el mismo Program que usan el selector de fichas,
 * «Por agente», el emparejador y el Catálogo.
 *
 * Reglas comunes:
 *  - code obligatorio, único por institución (también frente a borrados en blando: el índice
 *    único (institution_id, code) los incluye) y con formato de identificador.
 *  - nombre único por institución, normalizado (sin mayúsculas, tildes ni espacios dobles).
 *  - línea obligatoria entre las líneas de PROGRAMA de la taxonomía central (sin la
 *    institucional); se acepta el slug o la etiqueta.
 *  - área solo en Microcredenciales (Program::lineHasAreas), obligatoria ahí y siempre una
 *    existente: el alta manual no crea áreas.
 *  - URL de la ficha opcional (la columna no admite NULL: sin URL se guarda vacía).
 *
 * La autorización la exige quien llama (ProgramPolicy::create); institution_id lo sella el
 * scope global. Cada alta queda en la auditoría común (program.created, método manual/masivo/
 * catálogo). Diferencias legítimas del formulario del Catálogo: elige el estado, el orden, las
 * etiquetas y el resto de campos, y admite programas sin línea.
 */
final class ProgramProvisioningService
{
    public const METHOD_MANUAL = 'manual';

    public const METHOD_BULK = 'importacion_masiva';

    /** Alta desde Catálogo → Nuevo programa (formulario completo del catálogo). */
    public const METHOD_CATALOG = 'catalogo';

    public const MAX_BULK_ROWS = 500;

    /** Formato de code: letras, números, punto, guion y guion bajo; empieza por letra o número. */
    public const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** URL de la ficha (opcional): si se indica, absoluta http(s). */
    public const URL_PATTERN = '#^https?://\S+$#i';

    public function __construct(private readonly AuditService $audit) {}

    // --- Reglas comunes (las usan también Catálogo → Nuevo/Editar programa) ------------------

    /** Nombre normalizado para guardar: espacios simples y sin espacios en los extremos. */
    public function cleanName(?string $name): string
    {
        return $this->squish($name);
    }

    public static function codeFormatMessage(): string
    {
        return __('Código no válido: usa letras, números, punto, guion o guion bajo (máx. 40).');
    }

    public static function urlFormatMessage(): string
    {
        return __('La URL de la ficha debe empezar por http:// o https://.');
    }

    /**
     * Programa de la institución activa que choca con este alta/edición: mismo código (sin
     * distinguir mayúsculas, incluidos los borrados en blando, que el índice único también
     * cuenta) o mismo nombre normalizado. $exceptId excluye al propio programa al editar.
     */
    public function findDuplicate(string $code, string $name, ?int $exceptId = null): ?Program
    {
        $byCode = Program::withTrashed()
            ->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->first();
        if ($byCode !== null) {
            return $byCode;
        }

        $wanted = $this->normalizeName($name);

        return Program::query()
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->get(['id', 'code', 'name_es', 'status', 'deleted_at'])
            ->first(fn (Program $p): bool => $this->normalizeName((string) $p->name_es) === $wanted);
    }

    public function duplicateMessage(Program $existing): string
    {
        $label = $existing->code.' · '.$existing->name_es;

        return match (true) {
            $existing->trashed() => __('Ya existe un programa con este nombre o código (:p), eliminado del catálogo.', ['p' => $label]),
            $existing->status !== 'active' => __('Ya existe un programa con este nombre o código (:p), inactivo.', ['p' => $label]),
            default => __('Ya existe un programa con este nombre o código (:p).', ['p' => $label]),
        };
    }

    /** Auditoría común de un alta (usuario, institución y fecha los sella AuditService). */
    public function recordCreated(Program $program, string $method): void
    {
        $this->audit->log('program.created', $program, [
            'code' => $program->code,
            'name' => $program->name_es,
            'line' => $program->line,
            'method' => $method,
        ]);
    }

    /**
     * Valida y normaliza una fila sin escribir nada. `area` es el id (alta individual) o el
     * nombre del área (alta masiva).
     *
     * @param  array{name?: ?string, code?: ?string, line?: ?string, area?: int|string|null, url?: ?string}  $input
     * @return array{status: 'create'|'duplicate'|'inactive'|'error', errors: array<string, string>, existing: ?Program, data: array{name: string, code: string, line: ?string, category_id: ?int, url: string}}
     */
    public function check(array $input): array
    {
        $name = $this->squish($input['name'] ?? '');
        $code = trim((string) ($input['code'] ?? ''));
        $lineText = trim((string) ($input['line'] ?? ''));
        $url = trim((string) ($input['url'] ?? ''));
        $line = $lineText === '' ? null : $this->resolveLine($lineText);

        $errors = [];
        if ($name === '') {
            $errors['name'] = __('El nombre es obligatorio.');
        } elseif (mb_strlen($name) > 200) {
            $errors['name'] = __('El nombre admite como máximo 200 caracteres.');
        }

        if ($code === '') {
            $errors['code'] = __('El código es obligatorio.');
        } elseif (mb_strlen($code) > 40 || preg_match(self::CODE_PATTERN, $code) !== 1) {
            $errors['code'] = self::codeFormatMessage();
        }

        if ($lineText === '') {
            $errors['line'] = __('La línea es obligatoria.');
        } elseif ($line === null) {
            $errors['line'] = __('Línea no válida: «:line».', ['line' => $lineText]);
        }

        $categoryId = null;
        if ($line !== null && Program::lineHasAreas($line)) {
            $categoryId = $this->resolveArea($input['area'] ?? null);
            if ($categoryId === null) {
                $errors['area'] = blank($input['area'] ?? null)
                    ? __('El área es obligatoria en Microcredenciales.')
                    : __('Área no válida: «:area».', ['area' => (string) $input['area']]);
            }
        }

        if ($url !== '' && (mb_strlen($url) > 500 || ! preg_match(self::URL_PATTERN, $url))) {
            $errors['url'] = self::urlFormatMessage();
        }

        $data = ['name' => $name, 'code' => $code, 'line' => $line, 'category_id' => $categoryId, 'url' => $url];

        if ($errors !== []) {
            return ['status' => 'error', 'errors' => $errors, 'existing' => null, 'data' => $data];
        }

        $existing = $this->findDuplicate($code, $name);
        if ($existing !== null) {
            return [
                'status' => $existing->status !== 'active' && ! $existing->trashed() ? 'inactive' : 'duplicate',
                'errors' => ['code' => $this->duplicateMessage($existing)],
                'existing' => $existing,
                'data' => $data,
            ];
        }

        return ['status' => 'create', 'errors' => [], 'existing' => null, 'data' => $data];
    }

    /**
     * Crea el programa (activo) si la fila es válida y no duplicada. Devuelve el resultado de
     * check() y, si se creó, el Program en 'program'.
     *
     * @param  array{name?: ?string, code?: ?string, line?: ?string, area?: int|string|null, url?: ?string}  $input
     * @return array{status: 'created'|'duplicate'|'inactive'|'error', errors: array<string, string>, existing: ?Program, program: ?Program}
     */
    public function create(array $input, string $method = self::METHOD_MANUAL): array
    {
        $check = $this->check($input);
        if ($check['status'] !== 'create') {
            return ['status' => $check['status'], 'errors' => $check['errors'], 'existing' => $check['existing'], 'program' => null];
        }

        $data = $check['data'];
        $program = new Program;
        $program->code = $data['code'];
        $program->name_es = $data['name'];
        $program->line = $data['line'];
        $program->category_id = $data['category_id'];
        $program->url = $data['url'];
        $program->status = 'active';
        $program->display_order = (int) Program::query()->max('display_order') + 1;

        try {
            $program->save();
        } catch (QueryException $e) {
            // Carrera con otra alta del mismo código: el índice único decide; no se duplica.
            $existing = $this->findDuplicate($data['code'], $data['name']);
            if ($existing === null) {
                throw $e;
            }

            return ['status' => 'duplicate', 'errors' => ['code' => $this->duplicateMessage($existing)], 'existing' => $existing, 'program' => null];
        }

        $this->recordCreated($program, $method);

        return ['status' => 'created', 'errors' => [], 'existing' => null, 'program' => $program];
    }

    /** Reactiva un programa inactivo del catálogo en lugar de duplicarlo. */
    public function activate(Program $program, string $method = self::METHOD_MANUAL): Program
    {
        if ($program->status !== 'active') {
            $program->status = 'active';
            $program->save();
            $this->audit->log('program.activated', $program, ['code' => $program->code, 'method' => $method]);
        }

        return $program;
    }

    /**
     * Revisión de un alta masiva SIN escribir: una fila por línea del texto pegado
     * («nombre | código | línea | área | url», también con tabuladores o punto y coma; área y
     * URL opcionales). Los códigos y nombres repetidos dentro del propio texto cuentan como
     * duplicados a partir de la segunda aparición.
     *
     * @return array{rows: array<int, array{line: int, name: string, code: string, program_line: string, area: string, url: string, status: string, message: string, input: array{name: string, code: string, line: string, area: string, url: string}}>, summary: array{create: int, duplicate: int, error: int}, truncated: bool}
     */
    public function previewBulk(string $text): array
    {
        $rows = [];
        $seenCodes = [];
        $seenNames = [];
        $parsed = $this->parseBulk($text);

        foreach (array_slice($parsed, 0, self::MAX_BULK_ROWS) as $raw) {
            [$name, $code, $line, $area, $url] = array_pad($raw['cells'], 5, '');
            $input = ['name' => $name, 'code' => $code, 'line' => $line, 'area' => $area, 'url' => $url];
            $check = $this->check($input);
            $status = $check['status'] === 'inactive' ? 'duplicate' : $check['status'];
            $message = implode(' ', $check['errors']);

            $codeKey = mb_strtolower($check['data']['code']);
            $nameKey = $this->normalizeName($check['data']['name']);
            if ($status === 'create' && (isset($seenCodes[$codeKey]) || isset($seenNames[$nameKey]))) {
                $status = 'duplicate';
                $message = __('Repetido en la lista (línea :n).', ['n' => $seenCodes[$codeKey] ?? $seenNames[$nameKey]]);
            }
            if ($status === 'create') {
                $seenCodes[$codeKey] = $raw['line'];
                $seenNames[$nameKey] = $raw['line'];
            }

            $rows[] = [
                'line' => $raw['line'],
                'name' => $check['data']['name'],
                'code' => $check['data']['code'],
                'program_line' => $check['data']['line'] !== null ? (string) KnowledgeTaxonomy::lineLabel($check['data']['line']) : (string) $line,
                'area' => (string) $area,
                'url' => $check['data']['url'],
                'status' => $status,
                'message' => $message,
                'input' => $input,
            ];
        }

        $summary = ['create' => 0, 'duplicate' => 0, 'error' => 0];
        foreach ($rows as $row) {
            $summary[$row['status']]++;
        }

        return ['rows' => $rows, 'summary' => $summary, 'truncated' => count($parsed) > self::MAX_BULK_ROWS];
    }

    /**
     * Alta masiva: vuelve a revisar el texto en el servidor y crea SOLO las filas válidas; los
     * duplicados y los errores se omiten sin abortar el resto.
     *
     * @return array{created: int, duplicate: int, error: int, created_ids: array<int, int>, rows: array<int, array<string, mixed>>}
     */
    public function importBulk(string $text): array
    {
        $preview = $this->previewBulk($text);
        $result = ['created' => 0, 'duplicate' => $preview['summary']['duplicate'], 'error' => $preview['summary']['error'], 'created_ids' => [], 'rows' => $preview['rows']];

        foreach ($preview['rows'] as $i => $row) {
            if ($row['status'] !== 'create') {
                continue;
            }
            $outcome = $this->create($row['input'], self::METHOD_BULK);

            if ($outcome['status'] === 'created' && $outcome['program'] !== null) {
                $result['created']++;
                $result['created_ids'][] = (int) $outcome['program']->getKey();
                $result['rows'][$i]['status'] = 'created';
            } else {
                $result['duplicate']++;
                $result['rows'][$i]['status'] = 'duplicate';
                $result['rows'][$i]['message'] = implode(' ', $outcome['errors']);
            }
        }

        return $result;
    }

    /** @return array<int, array{line: int, cells: array<int, string>}> */
    private function parseBulk(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $index => $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $cells = $this->splitCells($raw);
            // Cabecera opcional («Nombre | Código | Línea …»).
            if ($rows === [] && $this->normalizeName($cells[0] ?? '') === 'nombre') {
                continue;
            }
            $rows[] = ['line' => $index + 1, 'cells' => $cells];
        }

        return $rows;
    }

    /** @return array<int, string> */
    private function splitCells(string $raw): array
    {
        $separator = str_contains($raw, "\t") ? "\t" : (str_contains($raw, '|') ? '|' : ';');

        return array_map(fn (string $c): string => trim($c), explode($separator, $raw));
    }

    /** Línea por el helper central (slug o etiqueta); solo las líneas de programa (sin la institucional). */
    private function resolveLine(string $text): ?string
    {
        $line = KnowledgeTaxonomy::lineFromLabel($text);

        return $line !== null && array_key_exists($line, KnowledgeTaxonomy::programLines()) ? $line : null;
    }

    /** Área existente por id o por nombre (sin mayúsculas ni tildes). Nunca crea áreas. */
    private function resolveArea(int|string|null $area): ?int
    {
        if (blank($area)) {
            return null;
        }
        if (is_int($area) || ctype_digit((string) $area)) {
            $id = ProgramCategory::query()->whereKey((int) $area)->value('id');

            return $id === null ? null : (int) $id;
        }

        $wanted = $this->normalizeName((string) $area);
        $match = ProgramCategory::query()->get(['id', 'name_es'])
            ->first(fn (ProgramCategory $c): bool => $this->normalizeName((string) $c->name_es) === $wanted);

        return $match === null ? null : (int) $match->getKey();
    }

    private function normalizeName(string $name): string
    {
        return Str::lower(Str::ascii($this->squish($name)));
    }

    private function squish(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }
}
