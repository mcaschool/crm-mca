<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Catalog\Database\Factories\ProgramFactory;
use Modules\Core\Concerns\HasTranslatedColumns;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Programa del catalogo estructurado. Sirve para EMPAREJAR al prospecto y
 * ENLAZAR a la ficha en la web; no recita precio ni temario (viven en la web).
 *
 * Campos bilingues por columnas _es/_en. SoftDeletes (D10: no se versiona el
 * catalogo; se borra en blando para no romper FKs historicas de leads/intereses).
 *
 * @property int $institution_id
 * @property string $code
 * @property string $name_es
 * @property string|null $name_en
 * @property string|null $credential_en
 * @property int|null $category_id
 * @property string|null $level
 * @property string|null $goal
 * @property string|null $profile
 * @property string|null $duration_es
 * @property string|null $duration_en
 * @property string|null $modality_es
 * @property string|null $modality_en
 * @property string|null $short_description_es
 * @property string|null $short_description_en
 * @property string $url
 * @property string $status
 * @property int $display_order
 */
class Program extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<ProgramFactory> */
    use HasFactory;

    use HasTranslatedColumns;
    use SoftDeletes;

    /** @var array<int,string> */
    protected array $translatable = ['name', 'duration', 'modality', 'short_description'];

    protected $fillable = [
        'institution_id',
        'code',
        'name_es',
        'name_en',
        'credential_en',
        'category_id',
        'level',
        'goal',
        'profile',
        'duration_es',
        'duration_en',
        'modality_es',
        'modality_en',
        'short_description_es',
        'short_description_en',
        'url',
        'status',
        'display_order',
    ];

    /**
     * @return BelongsTo<ProgramCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProgramCategory::class, 'category_id');
    }

    /**
     * @return HasMany<ProgramTag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(ProgramTag::class);
    }

    protected static function newFactory(): ProgramFactory
    {
        return ProgramFactory::new();
    }
}
