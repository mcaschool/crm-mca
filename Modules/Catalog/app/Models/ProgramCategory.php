<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Catalog\Database\Factories\ProgramCategoryFactory;
use Modules\Core\Concerns\HasTranslatedColumns;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Categoria de programa (= "area"). Nombre bilingue por columnas _es/_en.
 *
 * @property int $institution_id
 * @property string $name_es
 * @property string|null $name_en
 * @property string $slug
 * @property int $display_order
 * @property string $status
 */
class ProgramCategory extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<ProgramCategoryFactory> */
    use HasFactory;

    use HasTranslatedColumns;

    /** @var array<int,string> Campos con version _es/_en. */
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
     * @return HasMany<Program, $this>
     */
    public function programs(): HasMany
    {
        return $this->hasMany(Program::class, 'category_id');
    }

    protected static function newFactory(): ProgramCategoryFactory
    {
        return ProgramCategoryFactory::new();
    }
}
