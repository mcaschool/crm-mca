<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Facades\Log;
use Modules\Ai\Models\AiUsageEvent;
use Modules\Core\Tenancy\CurrentInstitution;
use Throwable;

/**
 * Persiste la telemetría GLOBAL de IA (ai_usage_events) a partir del contexto de
 * ejecución y las métricas normalizadas. NUNCA guarda prompts, respuestas ni secretos.
 * Un fallo de telemetría JAMÁS rompe la petición del usuario.
 */
final class AiUsageRecorder
{
    public function __construct(private readonly CurrentInstitution $tenancy) {}

    public function success(AiExecutionContext $ctx, AiChatResponse $res): void
    {
        $this->write($ctx, 'success', $res, null);
    }

    public function failure(AiExecutionContext $ctx, ?AiProviderException $err): void
    {
        $this->write($ctx, 'error', null, $err);
    }

    private function write(AiExecutionContext $ctx, string $status, ?AiChatResponse $res, ?AiProviderException $err): void
    {
        try {
            $metrics = $res !== null
                ? [
                    'input_tokens' => $res->inputTokens(),
                    'cached_input_tokens' => $res->cachedInputTokens,
                    'uncached_input_tokens' => $res->uncachedInputTokens(),
                    'output_tokens' => $res->outputTokens(),
                    'reasoning_tokens' => $res->reasoningTokens,
                    'latency_ms' => $res->latencyMs,
                ]
                : ['input_tokens' => 0, 'cached_input_tokens' => 0, 'uncached_input_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'latency_ms' => 0];

            $this->tenancy->runFor($ctx->institutionId, function () use ($ctx, $status, $metrics, $err): void {
                AiUsageEvent::query()->create(array_merge([
                    'institution_id' => $ctx->institutionId,
                    'process' => $ctx->process,
                    'agent_id' => $ctx->agentId,
                    'bot_id' => $ctx->botId,
                    'integration_id' => $ctx->integrationId,
                    'provider' => $ctx->provider,
                    'model' => $ctx->model,
                    'status' => $status,
                    'error_category' => $err?->category->value,
                    'created_at' => now(),
                ], $metrics));
            });
        } catch (Throwable $e) {
            Log::warning('ai.usage: no se pudo registrar el evento', ['error' => $e->getMessage()]);
        }
    }
}
