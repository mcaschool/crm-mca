<?php

declare(strict_types=1);

namespace Modules\Social\Services;

/**
 * Resultado de intentar enviar un mensaje saliente a Meta.
 *  - sent:          Meta aceptó; externalId trae el message id devuelto.
 *  - failed_window: Meta rechazó por estar FUERA de la ventana de 24h (code 10 / 2018278).
 *  - failed:        error confirmado (API) o de red ANTES de conectar: no llegó a Meta.
 *  - delivery_unknown: la red falló cuando la petición ya pudo llegar (tiempo agotado tras
 *                   conectar): Meta pudo aceptarlo. Nunca se reenvía automáticamente.
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

    public static function unknown(?string $error = null): self
    {
        return new self('delivery_unknown', null, $error);
    }

    /**
     * Fallo de red: si ocurrió ANTES de establecer la conexión (DNS, conexión rechazada o tiempo
     * agotado al conectar), Meta no recibió nada → failed. Cualquier otro (tiempo agotado tras
     * enviar, conexión cortada) es ambiguo → delivery_unknown.
     */
    public static function network(\Throwable $e): self
    {
        $before = (bool) preg_match('/cURL error (6|7)\b|Could not resolve host|Failed to connect|Connection refused|Connection timed out after/i', $e->getMessage());

        return $before ? self::failed('No se pudo conectar con Meta (red).') : self::unknown('Sin respuesta de Meta: no se sabe si recibió el mensaje.');
    }

    public static function failed(?string $error = null, ?int $code = null): self
    {
        return new self('failed', null, $error, $code);
    }
}
