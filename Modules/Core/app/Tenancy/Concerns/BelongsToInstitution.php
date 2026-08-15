<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Contracts\TenantScoped;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Core\Tenancy\Scopes\InstitutionScope;
use Modules\Institutions\Models\Institution;

/**
 * Aisla un modelo por institucion de forma AUTOMATICA (multi-tenancy).
 *
 * Aplica a TODOS los modelos de dominio. Dos efectos:
 *  - Lectura: el InstitutionScope filtra sola cada consulta (Capa 2).
 *  - Escritura: al crear, sella institution_id desde el contexto si falta (Capa 2).
 *
 * NO se aplica a modelos de infraestructura de identidad/tenant:
 *  - Institution (es la raiz del tenant),
 *  - User (la autenticacion ocurre por email global, antes de fijar contexto).
 *
 * @see \Modules\Core\Tenancy\CurrentInstitution
 * @see \Modules\Core\Tenancy\Scopes\InstitutionScope
 */
trait BelongsToInstitution
{
    public static function bootBelongsToInstitution(): void
    {
        static::addGlobalScope(new InstitutionScope);

        static::creating(function (Model $model): void {
            $column = $model instanceof TenantScoped
                ? $model->getInstitutionColumn()
                : 'institution_id';

            if ($model->getAttribute($column) === null) {
                $context = app(CurrentInstitution::class);
                // idOrFail: si no hay contexto, fallo ruidoso tambien al crear.
                $model->setAttribute($column, $context->idOrFail($model::class));
            }
        });
    }

    /**
     * Nombre de la columna de tenant. Sobrescribible por si algun modelo la nombra distinto.
     */
    public function getInstitutionColumn(): string
    {
        return 'institution_id';
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, $this->getInstitutionColumn());
    }

    /**
     * Consulta explicita para una institucion concreta, sin depender del contexto.
     * Util en comandos que iteran instituciones.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeForInstitution(Builder $query, int $institutionId): Builder
    {
        return $query->withoutGlobalScope(InstitutionScope::class)
            ->where($this->getInstitutionColumn(), $institutionId);
    }
}
