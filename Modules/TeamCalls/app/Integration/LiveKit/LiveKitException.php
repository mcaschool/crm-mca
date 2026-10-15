<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\LiveKit;

use RuntimeException;

/**
 * Fallo al hablar con el API de LiveKit. $kind: unreachable (red/tiempo), not_found (sala o
 * participante inexistente: las operaciones idempotentes lo tratan como éxito) o rejected (respuesta de
 * error). El mensaje nunca contiene tokens ni secretos.
 */
final class LiveKitException extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
