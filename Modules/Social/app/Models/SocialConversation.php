<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Social\Database\Factories\SocialConversationFactory;

/**
 * Conversación (hilo) de un canal social con un contacto. `provider` está denormalizado
 * para ícono/filtro sin join. Acotada por institución.
 *
 * @property int $institution_id
 * @property int $social_channel_id
 * @property string $provider
 * @property string $external_conversation_id
 * @property string|null $contact_name
 * @property string|null $contact_external_id
 * @property string|null $contact_avatar_url
 * @property string $status
 * @property int|null $assigned_to
 * @property int $unread_count
 * @property string|null $last_message_preview
 * @property \Illuminate\Support\Carbon|null $last_message_at
 */
class SocialConversation extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<SocialConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'social_channel_id',
        'provider',
        'external_conversation_id',
        'contact_name',
        'contact_external_id',
        'contact_avatar_url',
        'status',
        'assigned_to',
        'unread_count',
        'last_message_preview',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SocialChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(SocialChannel::class, 'social_channel_id');
    }

    /**
     * @return HasMany<SocialMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SocialMessage::class);
    }

    /**
     * Agente del panel asignado a la conversación.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    protected static function newFactory(): SocialConversationFactory
    {
        return SocialConversationFactory::new();
    }
}
