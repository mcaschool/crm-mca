<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Support;

use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Services\AiChatResponse;
use Modules\Integrations\Models\Integration;
use RuntimeException;

/**
 * Doble del proveedor de IA para las pruebas. NUNCA llama a Qwen ni a ningun
 * proveedor real: devuelve una respuesta predefinida y guarda las llamadas para
 * poder inspeccionar el prompt/mensajes que Celia envio.
 */
class FakeAiChatClient implements AiChatClient
{
    /** @var array<int, array{integration: Integration, model: string, messages: array<int,array{role:string,content:string}>, params: array<string,mixed>}> */
    public array $calls = [];

    private string $content;

    private bool $throw = false;

    private int $promptTokens = 42;

    private int $completionTokens = 18;

    public function __construct(string $content = '{"reply": "Respuesta de prueba", "action": "answer"}')
    {
        $this->content = $content;
    }

    /** Fija el contenido crudo que devolvera el "modelo" en la proxima llamada. */
    public function willReturn(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    /** Simula un fallo del proveedor (timeout, 500...). */
    public function willThrow(): self
    {
        $this->throw = true;

        return $this;
    }

    public function chat(Integration $integration, string $model, array $messages, array $params = []): AiChatResponse
    {
        $this->calls[] = compact('integration', 'model', 'messages', 'params');

        if ($this->throw) {
            throw new RuntimeException('Fallo simulado del proveedor.');
        }

        return new AiChatResponse(
            content: $this->content,
            provider: (string) ($integration->provider ?? 'qwen'),
            model: $model,
            promptTokens: $this->promptTokens,
            completionTokens: $this->completionTokens,
            latencyMs: 123,
        );
    }

    /** Numero de veces que se invoco al proveedor (para verificar deflexion). */
    public function callCount(): int
    {
        return count($this->calls);
    }
}
