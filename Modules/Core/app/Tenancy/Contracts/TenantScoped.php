<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Contracts;

/**
 * Contrato de un modelo aislado por institucion. Lo implementa el trait
 * BelongsToInstitution, de modo que el InstitutionScope puede resolver el
 * nombre de la columna de tenant con tipos correctos (sin aserciones).
 */
interface TenantScoped
{
    public function getInstitutionColumn(): string;
}
