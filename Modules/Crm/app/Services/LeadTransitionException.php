<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use InvalidArgumentException;

/**
 * Cambio de etapa rechazado por las reglas comerciales. Extiende InvalidArgumentException para que
 * los llamadores existentes (ficha del lead) lo sigan tratando igual.
 *
 * Motivos: terminal (Matriculado no cambia) · stale (otro usuario lo cambió antes) · invalid
 */
final class LeadTransitionException extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            'terminal' => __('El lead está matriculado: es una etapa final y no puede cambiar.'),
            'stale' => __('Otra persona cambió la etapa de este lead hace un momento. Se ha actualizado el tablero; revisa y vuelve a intentarlo.'),
            default => __('Ese cambio de etapa no está permitido.'),
        };
    }
}
