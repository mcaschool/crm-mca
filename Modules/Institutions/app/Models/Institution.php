<?php

declare(strict_types=1);

namespace Modules\Institutions\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Institutions\Database\Factories\InstitutionFactory;

/**
 * Institucion: raiz del multi-tenancy. Envuelve todo el sistema.
 *
 * NO usa BelongsToInstitution: es la propia entidad tenant, no pertenece a otra.
 */
class Institution extends Model
{
    /** @use HasFactory<InstitutionFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'timezone',
        'default_language',
    ];

    protected $attributes = [
        'status' => 'active',
        'timezone' => 'America/New_York',
        'default_language' => 'es',
    ];

    /**
     * @return HasMany<Bot, $this>
     */
    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }

    protected static function newFactory(): InstitutionFactory
    {
        return InstitutionFactory::new();
    }
}
