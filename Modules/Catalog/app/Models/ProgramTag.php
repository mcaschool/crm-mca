<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalog\Database\Factories\ProgramTagFactory;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Etiqueta libre de un programa (para el emparejador). Lleva institution_id
 * aunque cuelgue de program: el aislamiento es universal, sin excepciones.
 */
class ProgramTag extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<ProgramTagFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'program_id',
        'tag',
    ];

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    protected static function newFactory(): ProgramTagFactory
    {
        return ProgramTagFactory::new();
    }
}
