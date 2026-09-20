<?php

declare(strict_types=1);

namespace Modules\Mcp\Support;

use RuntimeException;

/**
 * Error controlado de una herramienta MCP: su mensaje es apto para devolverse
 * al asistente (isError=true), sin trazas internas ni secretos.
 */
final class McpToolException extends RuntimeException {}
