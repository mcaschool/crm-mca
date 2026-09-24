<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

/**
 * Contexto de ejecución de una llamada de IA: QUIÉN la hizo. Lo entrega la capa
 * superior (agente/proceso) de forma EXPLÍCITA, para que el transporte no tenga que
 * descubrirlo consultando otras tablas y para que métricas, alertas y auditoría
 * sepan institución/proceso/agente/bot/integración/proveedor/modelo.
 *
 * NO transporta metadata operacional dentro de params: esto es un objeto aparte.
 * NO contiene prompts, respuestas ni secretos.
 */
final class AiExecutionContext
{
    public function __construct(
        public readonly int $institutionId,
        public readonly string $process,
        public readonly int $integrationId,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?int $botId = null,
        public readonly ?int $agentId = null,
    ) {}

    /**
     * Campos seguros para logs/alertas (sin secretos ni contenido de usuario).
     *
     * @return array<string,mixed>
     */
    public function toLog(): array
    {
        return [
            'institution_id' => $this->institutionId,
            'process' => $this->process,
            'agent_id' => $this->agentId,
            'bot_id' => $this->botId,
            'integration_id' => $this->integrationId,
            'provider' => $this->provider,
            'model' => $this->model,
        ];
    }
}
