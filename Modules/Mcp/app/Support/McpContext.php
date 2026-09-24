<?php

declare(strict_types=1);

namespace Modules\Mcp\Support;

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Mcp\Models\McpClient;

/**
 * Contexto de ejecución de una llamada MCP: cliente autenticado + resolución
 * multi-institución. Reglas:
 *  - cliente ACOTADO a una institución → SIEMPRE opera en ella; si pide otra
 *    vía institution_id, se rechaza (sin mezclas accidentales entre instituciones);
 *  - cliente GLOBAL + institution_id en los argumentos → opera en esa institución;
 *  - cliente GLOBAL sin institution_id → modo global (transversal, sin scope).
 */
final class McpContext
{
    /**
     * @param  array<int,string>|null  $scopes  scopes del token OAuth (null = Bearer estático, sin restricción por scope)
     */
    public function __construct(
        public readonly McpClient $client,
        public readonly string $correlationId,
        public readonly ?array $scopes = null,
    ) {}

    /**
     * Institución efectiva para los argumentos dados (null = modo global).
     *
     * @param  array<string, mixed>  $arguments
     */
    public function institutionId(array $arguments): ?int
    {
        $requested = $arguments['institution_id'] ?? null;
        $requested = is_numeric($requested) ? (int) $requested : null;

        if (! $this->client->isGlobal()) {
            if ($requested !== null && $requested !== $this->client->institution_id) {
                throw new McpToolException('Este cliente MCP está acotado a la institución '.$this->client->institution_id.' y no puede operar sobre otra.');
            }

            return $this->client->institution_id;
        }

        return $requested;
    }

    /**
     * Ejecuta el callback en el contexto de institución que corresponda.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function run(array $arguments, callable $callback): mixed
    {
        $institutionId = $this->institutionId($arguments);
        $context = app(CurrentInstitution::class);

        return $institutionId !== null
            ? $context->runFor($institutionId, $callback)
            : $context->runGlobally($callback);
    }
}
