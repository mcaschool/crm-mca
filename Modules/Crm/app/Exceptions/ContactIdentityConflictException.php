<?php

declare(strict_types=1);

namespace Modules\Crm\Exceptions;

use RuntimeException;

/**
 * El email y el teléfono normalizado de una solicitud apuntan a DOS contactos existentes
 * DISTINTOS dentro de la misma institución. No se fusionan ni reasignan contactos de forma
 * automática: se lanza este conflicto controlado para que el llamador responda de forma
 * segura (y audite sin datos personales). Solo transporta los IDs (nunca email/teléfono).
 */
final class ContactIdentityConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $emailContactId,
        public readonly int $phoneContactId,
    ) {
        parent::__construct('El email y el teléfono corresponden a contactos distintos; no se fusionan automáticamente.');
    }
}
