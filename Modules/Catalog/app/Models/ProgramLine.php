<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Catalog\Database\Factories\ProgramLineFactory;
use Modules\Core\Concerns\HasTranslatedColumns;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Categoría de FORMACIÓN (línea del catálogo): Microcredenciales, Programas Ejecutivos,
 * Diplomas Avanzados, etc. Eje separado de las áreas temáticas (ProgramCategory). Nombre
 * bilingüe por columnas _es/_en. Acotada por institución.
 *
 * @property int $institution_id
 * @property string $name_es
 * @property string|null $name_en
 * @property string $slug
 * @property int $display_order
 * @property string $status
 */
class ProgramLine extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<ProgramLineFactory> */
    use HasFactory;

    use HasTranslatedColumns;

    /** @var array<int,string> Campos con versión _es/_en. */
    protected array $translatable = ['name'];

    protected $fillable = [
        'institution_id',
        'name_es',
        'name_en',
        'slug',
        'display_order',
        'status',
    ];

    /**
     * Programas de esta categoría de formación.
     *
     * @return HasMany<Program, $this>
     */
    public function programs(): HasMany
    {
        return $this->hasMany(Program::class, 'line_id');
    }

    protected static function newFactory(): ProgramLineFactory
    {
        return ProgramLineFactory::new();
    }
}
