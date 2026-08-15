<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Crm\Database\Factories\ConversationFactory;

/**
 * Conversacion con un bot, en un modo (guided/celia) y un idioma.
 * `session_id` es un token opaco que vive en el localStorage del navegador.
 * `current_node_id` guarda el nodo actual del arbol para la recuperacion de
 * sesion del widget (anadido confirmado del Bloque 0).
 */
class Conversation extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'contact_id',
        'bot_id',
        'session_id',
        'channel',
        'mode',
        'language',
        'status',
        'current_node_id',
        'started_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    protected static function newFactory(): ConversationFactory
    {
        return ConversationFactory::new();
    }
}
