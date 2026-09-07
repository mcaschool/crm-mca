<?php

declare(strict_types=1);

namespace Modules\Social\Services;

/**
 * Resultado de intentar enviar un mensaje saliente a Meta.
 *  - sent:          Meta aceptó; externalId trae el message id devuelto.
 *  - failed_window: Meta rechazó por estar FUERA de la ventana de 24h (code 10 / 2018278).
 *  - failed:        cualquier otro error (API o red).
 */
final readonly class SendResult
{
    private function __construct(
        public string $status,
        public ?string $externalId,
        public ?string $errorMessage,
    ) {}

    public static function sent(string $externalId): self
    {
        return new self('sent', $externalId, null);
    }

    public static function window(?string $error = null): self
    {
        return new self('failed_window', null, $error);
    }

    public static function failed(?string $error = null): self
    {
        return new self('failed', null, $error);
    }
}
