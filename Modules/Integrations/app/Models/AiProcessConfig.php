<?php

declare(strict_types=1);

namespace Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Integrations\Database\Factories\AiProcessConfigFactory;

/**
 * Asignacion proveedor+modelo por PROCESO (conversar, clasificar, resumir,
 * redactar correo). Permite usar IA de mayor calidad solo donde hace falta.
 * bot_id nullable = configuracion por defecto de la institucion.
 */
class AiProcessConfig extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<AiProcessConfigFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'bot_id',
        'process',
        'integration_id',
        'model',
        'params',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    protected static function newFactory(): AiProcessConfigFactory
    {
        return AiProcessConfigFactory::new();
    }
}
