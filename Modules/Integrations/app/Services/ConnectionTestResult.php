<?php

declare(strict_types=1);

namespace Modules\Integrations\Services;

/**
 * Resultado de una prueba de conexion. `message` va SANEADO: nunca contiene el
 * secreto ni datos sensibles (se guarda tal cual en integrations.last_test_message
 * y se muestra en el panel).
 */
final class ConnectionTestResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly bool $pending = false,
    ) {}

    public static function ok(string $message = 'Conexion correcta.'): self
    {
        return new self(true, $message);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }

    /** El tipo aun no tiene prueba real (se implementa en su bloque). */
    public static function pending(string $message = 'Prueba pendiente de su bloque.'): self
    {
        return new self(false, $message, pending: true);
    }
}
