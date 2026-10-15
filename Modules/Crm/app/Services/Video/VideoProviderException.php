<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Video;

use RuntimeException;

/**
 * Error controlado de un proveedor de videoconferencia. El mensaje es SIEMPRE interno y saneado
 * (sin tokens, secretos ni cuerpos de respuesta); al usuario se le muestra userMessage().
 * $detail conserva, saneado, el motivo que devolvió el proveedor (p. ej. una restricción del plan
 * de Zoom) para comunicarlo tal cual; nunca se registra en el log.
 *
 * Tipos: config · auth · permission · license · rejected · not_found · invalid_response · transport · rate_limited
 */
final class VideoProviderException extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message = '', public readonly ?string $detail = null)
    {
        parent::__construct($message !== '' ? $message : $kind);
    }

    public function userMessage(): string
    {
        $base = match ($this->kind) {
            'config' => __('La conexión no está configurada por completo.'),
            'auth' => __('La autorización caducó o fue revocada. Vuelve a conectar la cuenta institucional.'),
            'permission' => __('La cuenta institucional no tiene permiso para esta operación.'),
            'license' => __('La licencia o la configuración de la cuenta no permite esta operación.'),
            'rejected' => __('El proveedor rechazó la operación.'),
            'not_found' => __('El recurso ya no existe en el proveedor.'),
            'invalid_response' => __('El proveedor devolvió una respuesta no válida.'),
            'rate_limited' => __('El proveedor limitó temporalmente las peticiones. Inténtalo más tarde.'),
            default => __('No se pudo contactar con el proveedor. Inténtalo más tarde.'),
        };

        return $this->detail !== null && $this->detail !== '' ? $base.' '.__('Respuesta del proveedor: «:detail»', ['detail' => $this->detail]) : $base;
    }
}
