<?php

declare(strict_types=1);

namespace Modules\Institutions\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Modules\Institutions\Database\Factories\InstitutionFactory;

/**
 * Institucion: raiz del multi-tenancy. Envuelve todo el sistema.
 *
 * NO usa BelongsToInstitution: es la propia entidad tenant, no pertenece a otra.
 *
 * @property string|null $logo_path
 */
class Institution extends Model
{
    /** @use HasFactory<InstitutionFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'logo_path',
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

    /** Carpeta del logo en el disco public: 'institutions/{id}'. */
    public function logoFolder(): string
    {
        return 'institutions/'.$this->getKey();
    }

    /** URL publica del logo institucional (o null si no hay). */
    public function logoUrl(): ?string
    {
        if ($this->logo_path === null || $this->logo_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    protected static function newFactory(): InstitutionFactory
    {
        return InstitutionFactory::new();
    }
}
