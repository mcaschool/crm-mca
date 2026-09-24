<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Modules\Ai\Enums\AiErrorCategory;
use RuntimeException;

/**
 * Error NORMALIZADO de un proveedor de IA. Lleva la categoría común (no el código
 * concreto de Alibaba/OpenAI), el status HTTP, el código del proveedor y el request
 * id para soporte. El mensaje es genérico: NUNCA contiene la API key ni el prompt.
 */
final class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly AiErrorCategory $category,
        public readonly int $httpStatus = 0,
        public readonly ?string $providerCode = null,
        public readonly ?string $requestId = null,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : ('AI provider error: '.$category->value));
    }
}
