<?php

declare(strict_types=1);

namespace Modules\Institutions\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Institutions\Database\Factories\BotFactory;

/**
 * Bot: entidad configurable de primera clase (no codigo). De el cuelgan su
 * conocimiento, su arbol conversacional y su configuracion de IA, por bot_id.
 *
 * `public_key` es el token opaco que el <script> del widget lleva embebido; el
 * servidor deduce la institucion desde ahi (nunca la envia el cliente).
 *
 * @property int $institution_id
 * @property string $name
 * @property string $assistant_name
 * @property string|null $landing_url
 * @property string $public_key
 * @property string $default_language
 * @property string $status
 */
class Bot extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<BotFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'name',
        'slug',
        'assistant_name',
        'landing_url',
        'public_key',
        'allowed_origins',
        'default_language',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
        ];
    }

    protected static function newFactory(): BotFactory
    {
        return BotFactory::new();
    }
}
