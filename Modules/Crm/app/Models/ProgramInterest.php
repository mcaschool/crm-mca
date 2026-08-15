<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Crm\Database\Factories\ProgramInterestFactory;

/**
 * Interes de un contacto por un programa. Append-only (solo created_at).
 * Sin UNIQUE: el interes repetido es senal comercial, no duplicado.
 */
class ProgramInterest extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<ProgramInterestFactory> */
    use HasFactory;

    /** Append-only: sin updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'institution_id',
        'contact_id',
        'program_id',
        'bot_id',
        'source',
    ];

    protected static function newFactory(): ProgramInterestFactory
    {
        return ProgramInterestFactory::new();
    }
}
