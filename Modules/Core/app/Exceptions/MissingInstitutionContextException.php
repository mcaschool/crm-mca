<?php

declare(strict_types=1);

namespace Modules\Core\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando una operacion de dominio se ejecuta sin una institucion
 * activa en el contexto. Es una BARANDILLA: preferimos un fallo ruidoso a una
 * consulta que cruce (o filtre) datos entre instituciones por error.
 *
 * @see \Modules\Core\Tenancy\CurrentInstitution
 * @see \Modules\Core\Tenancy\Concerns\BelongsToInstitution
 */
final class MissingInstitutionContextException extends RuntimeException
{
    public static function forQuery(string $model): self
    {
        return new self(
            "No hay institucion activa en el contexto al consultar [{$model}]. "
            .'Establece el contexto (middleware de panel/bot, o TenantAwareJob / comando con --institution) '
            .'antes de tocar modelos de dominio.'
        );
    }

    public static function forCreate(string $model): self
    {
        return new self(
            "No hay institucion activa en el contexto al crear [{$model}]. "
            .'Asigna institution_id explicitamente o establece el contexto de institucion.'
        );
    }
}
