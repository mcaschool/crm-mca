<?php

declare(strict_types=1);

namespace Modules\Social\Services;

/**
 * Resultado de intentar enviar un mensaje saliente a Meta.
 *  - sent:          Meta aceptó; externalId trae el message id devuelto.
 *  - failed_window: Meta rechazó por estar FUERA de la ventana de 24h (code 10 / 2018278).
 *  - failed:        cualquier otro error (API o red).
 * errorCode: código de error de Meta si lo hubo (p. ej. 190 = credencial caducada o inválida).
 */
final readonly class SendResult
{
    private function __construct(
        public string $status,
        public ?string $externalId,
        public ?string $errorMessage,
        public ?int $errorCode = null,
    ) {}

    /** Códigos de Meta de credencial caducada, revocada o inválida. */
    public const TOKEN_ERROR_CODES = [102, 190, 463, 467];

    public function tokenInvalid(): bool
    {
        return $this->errorCode !== null && in_array($this->errorCode, self::TOKEN_ERROR_CODES, true);
    }

    public static function sent(string $externalId): self
    {
        return new self('sent', $externalId, null);
    }

    public static function window(?string $error = null): self
    {
        return new self('failed_window', null, $error);
    }

    public static function failed(?string $error = null, ?int $code = null): self
    {
        return new self('failed', null, $error, $code);
    }
}
