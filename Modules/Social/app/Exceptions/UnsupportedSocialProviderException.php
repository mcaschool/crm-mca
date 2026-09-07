<?php

declare(strict_types=1);

namespace Modules\Social\Exceptions;

use RuntimeException;

/**
 * El envío saliente no está soportado para este proveedor en este bloque. Hoy WhatsApp
 * queda fuera (su envío va en su track propio); la UI ya lo tiene deshabilitado, así que
 * esta excepción es una barandilla defensiva del servicio.
 */
final class UnsupportedSocialProviderException extends RuntimeException
{
    public static function for(string $provider): self
    {
        return new self("Envío no soportado para el proveedor «{$provider}» en este bloque.");
    }
}
