<?php

declare(strict_types=1);

namespace Modules\Mcp\Support;

use RuntimeException;

/**
 * Error controlado de una herramienta MCP: su mensaje es apto para devolverse
 * al asistente (isError=true), sin trazas internas ni secretos.
 *
 * Puede llevar un challenge OAuth (`mcp/www_authenticate`) cuando el fallo es de
 * autorización/scope, para que ChatGPT sepa que debe (re)autorizar.
 */
final class McpToolException extends RuntimeException
{
    /** @var array<string,string>|null */
    public ?array $challenge = null;

    public static function insufficientScope(string $tool, string $scope): self
    {
        $e = new self(__('La herramienta :tool requiere el scope :scope, que este token no tiene.', ['tool' => $tool, 'scope' => $scope]));
        $e->challenge = [
            'error' => 'insufficient_scope',
            'error_description' => 'required scope: '.$scope,
            'scope' => $scope,
        ];

        return $e;
    }
}
