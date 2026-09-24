<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Modules\Integrations\Models\Integration;

/**
 * Contrato agnostico de proveedor de chat de IA. La implementacion concreta
 * (OpenAI-compatible: Qwen/OpenAI/DeepSeek/Kimi) se resuelve del contenedor; en
 * pruebas se sustituye por un doble (nunca se llama al proveedor real).
 */
interface AiChatClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $params  intención conceptual: temperature, max_tokens, structured, thinking, timeout...
     * @param  AiExecutionContext|null  $context  QUIÉN ejecuta (para métricas/alertas/auditoría); nunca metadata en params.
     */
    public function chat(Integration $integration, string $model, array $messages, array $params = [], ?AiExecutionContext $context = null): AiChatResponse;
}
