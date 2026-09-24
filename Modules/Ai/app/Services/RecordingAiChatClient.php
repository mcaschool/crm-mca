<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Modules\Integrations\Models\Integration;
use Throwable;

/**
 * Decorador de AiChatClient que centraliza la TELEMETRÍA para TODOS los consumidores:
 * registra cada llamada (éxito o error) en ai_usage_events. El transporte real queda
 * puro (solo habla HTTP). Es el único punto por el que pasa toda la IA del CRM, así que
 * cualquier agente/proceso nuevo hereda la observabilidad sin código.
 */
final class RecordingAiChatClient implements AiChatClient
{
    public function __construct(
        private readonly AiChatClient $inner,
        private readonly AiUsageRecorder $recorder,
    ) {}

    public function chat(Integration $integration, string $model, array $messages, array $params = [], ?AiExecutionContext $context = null): AiChatResponse
    {
        try {
            $res = $this->inner->chat($integration, $model, $messages, $params, $context);
        } catch (AiProviderException $e) {
            if ($context !== null) {
                $this->recorder->failure($context, $e);
            }
            throw $e;
        } catch (Throwable $e) {
            // Error no normalizado: telemetría sin categoría; se propaga.
            if ($context !== null) {
                $this->recorder->failure($context, null);
            }
            throw $e;
        }

        if ($context !== null) {
            $this->recorder->success($context, $res);
        }

        return $res;
    }
}
