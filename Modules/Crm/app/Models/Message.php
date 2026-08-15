<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Crm\Database\Factories\MessageFactory;

/**
 * Mensaje de una conversacion. Append-only, alto volumen: solo created_at.
 *
 * `meta` (JSON) guarda por mensaje: provider, model, prompt_tokens,
 * completion_tokens, latency_ms, cost_usd. Es la FUENTE para calcular el
 * AI Deflection Rate (anadido confirmado del Bloque 0): un mensaje resuelto sin
 * IA no lleva provider; uno con IA si. El ratio se deriva de ahi.
 *
 * @property int $institution_id
 * @property int $conversation_id
 * @property string $sender_type
 * @property string $content
 * @property string $message_type
 * @property array<string,mixed>|null $meta
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class Message extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /** Append-only: sin updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'institution_id',
        'conversation_id',
        'sender_type',
        'content',
        'message_type',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    protected static function newFactory(): MessageFactory
    {
        return MessageFactory::new();
    }
}
