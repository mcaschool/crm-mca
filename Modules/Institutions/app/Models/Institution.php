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
 * @property int $logo_size
 */
class Institution extends Model
{
    /** @use HasFactory<InstitutionFactory> */
    use HasFactory;

    /** Rango sano para el alto del logo en el sidebar (px). */
    public const LOGO_SIZE_MIN = 24;

    public const LOGO_SIZE_MAX = 96;

    public const LOGO_SIZE_DEFAULT = 44;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'logo_path',
        'logo_size',
        'timezone',
        'default_language',
    ];

    protected function casts(): array
    {
        return [
            'logo_size' => 'integer',
        ];
    }

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

    /** Alto del logo en el sidebar (px), acotado al rango sano. */
    public function logoSize(): int
    {
        $size = $this->logo_size ?: self::LOGO_SIZE_DEFAULT;

        return max(self::LOGO_SIZE_MIN, min(self::LOGO_SIZE_MAX, $size));
    }

    protected static function newFactory(): InstitutionFactory
    {
        return InstitutionFactory::new();
    }
}
