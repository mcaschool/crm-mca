<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Exceptions\MissingInstitutionContextException;
use Modules\Core\Tenancy\Contracts\TenantScoped;
use Modules\Core\Tenancy\CurrentInstitution;

/**
 * Scope global que filtra toda lectura por la institucion activa (Capa 2).
 *
 * Barandilla (Capa 3): si NO hay institucion activa y NO se pidio modo global
 * explicito, lanza MissingInstitutionContextException en lugar de devolver todo.
 * Un olvido de contexto se convierte en un fallo ruidoso, nunca en una fuga.
 *
 * @implements Scope<Model>
 */
final class InstitutionScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentInstitution::class);

        // Modo global explicito (CLI cross-tenant): no se aplica filtro.
        if ($context->isGlobal()) {
            return;
        }

        // La columna de tenant se resuelve via el contrato (siempre se cumple:
        // el scope solo se anade a modelos que usan BelongsToInstitution).
        $column = $model instanceof TenantScoped
            ? $model->getInstitutionColumn()
            : 'institution_id';

        if ($context->has()) {
            $builder->where($model->getTable().'.'.$column, $context->id());

            return;
        }

        // Ni institucion activa ni modo global: fallo ruidoso.
        throw MissingInstitutionContextException::forQuery($model::class);
    }
}
