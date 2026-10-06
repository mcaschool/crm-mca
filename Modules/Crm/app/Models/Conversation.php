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
 *
 * @property int $institution_id
 * @property int|null $contact_id
 * @property int $bot_id
 * @property string $session_id
 * @property string $channel web | preview | instagram | messenger | whatsapp
 * @property bool $is_test conversación de PRUEBA interna (nunca cuenta como producción)
 * @property string|null $external_id id de la conversación en el canal de origen
 * @property string $mode guided | celia | human (traspasada a una persona: el bot no responde)
 * @property string $language
 * @property string $status
 * @property int|null $current_node_id
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $last_activity_at
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
        'is_test',
        'external_id',
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
            'is_test' => 'boolean',
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

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    protected static function newFactory(): ConversationFactory
    {
        return ConversationFactory::new();
    }
}
