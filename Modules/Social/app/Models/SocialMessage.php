<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Social\Database\Factories\SocialMessageFactory;

/**
 * Mensaje de una conversación social (entrante/saliente). Acotado por institución.
 *
 * @property int $institution_id
 * @property int $social_conversation_id
 * @property string|null $external_message_id
 * @property string $direction
 * @property string $type
 * @property string|null $body
 * @property array<int|string,mixed>|null $attachments
 * @property string|null $status
 * @property string $sender_type
 * @property int|null $sent_by
 * @property \Illuminate\Support\Carbon|null $provider_timestamp
 */
class SocialMessage extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<SocialMessageFactory> */
    use HasFactory;

    /** Sentido del mensaje. */
    public const DIRECTIONS = ['inbound', 'outbound'];

    /**
     * Origen del mensaje: 'contact' (el usuario), 'agent' (un agente del panel) o
     * 'app' (el negocio respondiendo desde la app del teléfono — eco de coexistencia).
     */
    public const SENDER_TYPES = ['contact', 'agent', 'app'];

    protected $fillable = [
        'institution_id',
        'social_conversation_id',
        'external_message_id',
        'direction',
        'type',
        'body',
        'attachments',
        'status',
        'sender_type',
        'sent_by',
        'provider_timestamp',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'provider_timestamp' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SocialConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SocialConversation::class, 'social_conversation_id');
    }

    /**
     * Agente del panel que envió el mensaje (si fue saliente por un agente).
     *
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    protected static function newFactory(): SocialMessageFactory
    {
        return SocialMessageFactory::new();
    }
}
