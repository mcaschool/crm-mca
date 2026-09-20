<?php

declare(strict_types=1);

namespace Modules\Mcp\Tools;

use Modules\Mcp\Support\CodeSearcher;
use Modules\Mcp\Support\McpContext;
use Modules\Mcp\Support\McpToolException;

/**
 * Lectura y búsqueda de código del repositorio (solo lectura: los cambios de
 * código siguen el flujo local → Git → GitHub → Hostinger, nunca vía MCP).
 */
final class CodeTools
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function codeSearch(array $args, McpContext $ctx): array
    {
        $pattern = trim((string) ($args['pattern'] ?? ''));
        if ($pattern === '') {
            throw new McpToolException('Falta pattern (subcadena, o regex entre /.../).');
        }
        $results = CodeSearcher::search(
            $pattern,
            isset($args['path']) ? (string) $args['path'] : null,
            (int) ($args['limit'] ?? 40),
        );

        return ['pattern' => $pattern, 'count' => count($results), 'results' => $results];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function codeRead(array $args, McpContext $ctx): array
    {
        $path = trim((string) ($args['path'] ?? ''));
        if ($path === '') {
            throw new McpToolException('Falta path (relativo al repositorio).');
        }

        return CodeSearcher::read(
            $path,
            max(1, (int) ($args['from'] ?? 1)),
            isset($args['to']) ? (int) $args['to'] : null,
        );
    }
}
