<?php

declare(strict_types=1);

namespace Modules\Mcp\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Búsqueda y lectura de código del repositorio en PHP puro (sin exec: apto
 * para hosting compartido con funciones de shell restringidas). Solo entra en
 * los directorios de mcp.code_roots; NUNCA sirve .env*, storage ni vendor, y
 * bloquea cualquier intento de path traversal.
 */
final class CodeSearcher
{
    private const DENY_BASENAMES = ['.env', '.env.example', '.env.backup', '.env.production', 'auth.json'];

    private const SEARCH_EXTENSIONS = ['php', 'blade.php', 'js', 'json', 'md', 'css', 'xml', 'yml', 'yaml', 'stub'];

    /**
     * Segmentos de ruta NUNCA recorridos, aunque un code_root los contuviera
     * (p. ej. public/vendor, un node_modules publicado, symlinks, .git): código
     * de dependencias, stubs de PHP, cachés y artefactos de build/IDE quedan
     * fuera del alcance del asistente.
     */
    private const DENY_DIR_SEGMENTS = [
        'vendor', 'node_modules', '.git', 'bootstrap', 'storage',
        '.idea', '.vscode', '.fleet', 'dist', 'build', 'coverage',
    ];

    /** Tope de coincidencias por archivo (evita que un archivo domine el resultado). */
    private const MAX_MATCHES_PER_FILE = 20;

    /** Tope total de bytes de texto devueltos (defensa dura contra respuestas gigantes). */
    private const MAX_TOTAL_BYTES = 60_000;

    /** Tope de archivos examinados por búsqueda (defensa contra barridos enormes). */
    private const MAX_FILES_SCANNED = 6000;

    /**
     * Busca un patrón (subcadena o regex si viene entre /.../) en el código,
     * confinado al project root y a mcp.code_roots. Límites: `limit` resultados
     * (≤100), MAX_MATCHES_PER_FILE por archivo y MAX_TOTAL_BYTES en total.
     *
     * @return array<int, array{file: string, line: int, text: string}>
     */
    public static function search(string $pattern, ?string $path = null, int $limit = 40): array
    {
        $limit = max(1, min($limit, 100));
        $isRegex = strlen($pattern) > 2 && str_starts_with($pattern, '/') && strrpos($pattern, '/') > 0
            && @preg_match($pattern, '') !== false;

        $results = [];
        $bytes = 0;
        $scanned = 0;
        foreach (self::files($path) as $file) {
            if (++$scanned > self::MAX_FILES_SCANNED) {
                break;
            }
            $lines = @file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            $perFile = 0;
            foreach ($lines as $i => $line) {
                $hit = $isRegex ? preg_match($pattern, $line) === 1 : stripos($line, $pattern) !== false;
                if (! $hit) {
                    continue;
                }
                $text = mb_substr(trim($line), 0, 220);
                $results[] = [
                    'file' => self::relative($file->getPathname()),
                    'line' => $i + 1,
                    'text' => $text,
                ];
                $bytes += strlen($text);
                if (count($results) >= $limit || $bytes >= self::MAX_TOTAL_BYTES) {
                    return $results;
                }
                if (++$perFile >= self::MAX_MATCHES_PER_FILE) {
                    break; // este archivo ya aportó bastante; pasar al siguiente
                }
            }
        }

        return $results;
    }

    /**
     * Lee un archivo del repo (rango de líneas opcional), con las mismas vedas.
     *
     * @return array{file: string, total_lines: int, from: int, to: int, content: string}
     */
    public static function read(string $relativePath, int $from = 1, ?int $to = null): array
    {
        $maxLines = (int) config('mcp.max_lines', 400);
        $real = realpath(base_path($relativePath));
        if ($real === false || str_contains($relativePath, '..')) {
            throw new McpToolException('Archivo no encontrado: '.$relativePath);
        }
        self::assertReadable($real);

        $lines = @file($real, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new McpToolException('No se pudo leer el archivo.');
        }
        $total = count($lines);
        $from = max(1, $from);
        $to = min($to ?? ($from + $maxLines - 1), $from + $maxLines - 1, $total);
        $slice = array_slice($lines, $from - 1, $to - $from + 1, true);

        $content = '';
        foreach ($slice as $i => $line) {
            $content .= ($i + 1)."\t".$line."\n";
        }

        return [
            'file' => self::relative($real),
            'total_lines' => $total,
            'from' => $from,
            'to' => $to,
            'content' => $content,
        ];
    }

    /** Veda central: dentro del repo, dentro de code_roots, nunca secretos. */
    private static function assertReadable(string $real): void
    {
        $base = (string) realpath(base_path());
        if (! str_starts_with($real, $base.DIRECTORY_SEPARATOR)) {
            throw new McpToolException('Fuera del repositorio.');
        }
        $relative = ltrim(substr($real, strlen($base)), '/\\');
        $first = explode(DIRECTORY_SEPARATOR, str_replace('/', DIRECTORY_SEPARATOR, $relative))[0];

        $rootFiles = ['composer.json', 'composer.lock', 'phpstan.neon', 'phpunit.xml', 'pint.json', 'modules_statuses.json', 'CLAUDE.md', 'README.md', 'package.json', 'vite.config.js'];
        $roots = (array) config('mcp.code_roots', []);
        if (! in_array($first, $roots, true) && ! in_array($relative, $rootFiles, true)) {
            throw new McpToolException('Ruta fuera del alcance permitido (directorios: '.implode(', ', $roots).').');
        }
        if (self::inDeniedDir($relative)) {
            throw new McpToolException('Ese directorio está fuera del alcance de MCP (dependencias/artefactos).');
        }
        if (in_array(strtolower(basename($real)), self::DENY_BASENAMES, true)) {
            throw new McpToolException('Ese archivo no se sirve por MCP.');
        }
    }

    /** ¿La ruta relativa contiene algún segmento vedado (vendor, node_modules, .git…)? */
    private static function inDeniedDir(string $relative): bool
    {
        $segments = preg_split('#[/\\\\]#', $relative) ?: [];
        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), self::DENY_DIR_SEGMENTS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private static function files(?string $path): iterable
    {
        $roots = (array) config('mcp.code_roots', []);
        if ($path !== null && $path !== '') {
            $real = realpath(base_path($path));
            if ($real === false || str_contains($path, '..')) {
                return;
            }
            self::assertReadable(is_dir($real) ? $real.DIRECTORY_SEPARATOR.'.' : $real);
            $roots = [trim(str_replace(base_path(), '', is_dir($real) ? $real : dirname($real)), '/\\')];
        }

        foreach ($roots as $root) {
            $dir = base_path((string) $root);
            if (! is_dir($dir)) {
                continue;
            }
            // FOLLOW_SYMLINKS deshabilitado por omisión: en producción public/storage
            // enlaza a storage/app y NO debe recorrerse (ni node_modules si existiera).
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));
            $iterator->setMaxDepth(12);
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getSize() > 1_500_000) {
                    continue;
                }
                $name = strtolower($file->getFilename());
                if (in_array($name, self::DENY_BASENAMES, true)) {
                    continue;
                }
                // Segmento vedado en cualquier nivel (public/vendor, node_modules…).
                if (self::inDeniedDir(self::relative($file->getPathname()))) {
                    continue;
                }
                foreach (self::SEARCH_EXTENSIONS as $ext) {
                    if (str_ends_with($name, '.'.$ext)) {
                        yield $file;

                        continue 2;
                    }
                }
            }
        }
    }

    private static function relative(string $absolute): string
    {
        return str_replace('\\', '/', ltrim(str_replace((string) realpath(base_path()), '', $absolute), '/\\'));
    }
}
