<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Crm\Database\Factories\EventFactory;

/**
 * Evento: todo comportamiento deja rastro, aunque la respuesta sea un enlace.
 * Append-only, alto volumen: solo created_at.
 *
 * Las "preguntas no resueltas" de Celia se registran como event_type
 * = 'celia.unresolved' (sin tabla propia en el dia 1).
 *
 * @property int $institution_id
 * @property int|null $contact_id
 * @property int|null $conversation_id
 * @property int|null $bot_id
 * @property string $event_type
 * @property array<string,mixed>|null $event_data
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class Event extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /** Append-only: sin updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'institution_id',
        'contact_id',
        'conversation_id',
        'bot_id',
        'event_type',
        'event_data',
    ];

    protected function casts(): array
    {
        return [
            'event_data' => 'array',
        ];
    }

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }
}
