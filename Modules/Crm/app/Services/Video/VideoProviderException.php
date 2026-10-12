<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Video;

use RuntimeException;

/**
 * Error controlado de un proveedor de videoconferencia. El mensaje es SIEMPRE interno y saneado
 * (sin tokens, secretos ni cuerpos de respuesta); al usuario se le muestra userMessage().
 *
 * Tipos: config · auth · permission · license · advisor_not_host · not_found · invalid_response · transport · rate_limited
 */
final class VideoProviderException extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $kind);
    }

    public function userMessage(): string
    {
        return match ($this->kind) {
            'config' => __('La conexión no está configurada por completo.'),
            'auth' => __('La autorización caducó o fue revocada. Vuelve a conectar la cuenta institucional.'),
            'permission' => __('La cuenta institucional no tiene permiso para esta operación.'),
            'license' => __('La licencia o la configuración de la cuenta no permite esta operación.'),
            'advisor_not_host' => __('El asesor no es un usuario con licencia de la cuenta de Zoom institucional, así que no podría iniciar ni dirigir la reunión. No se ha creado ninguna sala: asígnale una licencia en Zoom o mantén su enlace manual.'),
            'not_found' => __('El recurso ya no existe en el proveedor.'),
            'invalid_response' => __('El proveedor devolvió una respuesta no válida.'),
            'rate_limited' => __('El proveedor limitó temporalmente las peticiones. Inténtalo más tarde.'),
            default => __('No se pudo contactar con el proveedor. Inténtalo más tarde.'),
        };
    }
}
