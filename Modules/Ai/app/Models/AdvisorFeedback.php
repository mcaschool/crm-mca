<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Crm\Models\Message;

/**
 * Valoración de una respuesta del asesor en el modo de prueba (ver la migración
 * create_advisor_feedback_table). Solo evidencia: no modifica prompt ni conocimiento.
 *
 * @property int $institution_id
 * @property int $bot_id
 * @property int $conversation_id
 * @property int $message_id
 * @property string $rating
 * @property string|null $comment
 * @property int|null $user_id
 */
class AdvisorFeedback extends Model
{
    use BelongsToInstitution;

    public const CORRECT = 'correct';

    public const NEEDS_IMPROVEMENT = 'needs_improvement';

    protected $table = 'advisor_feedback';

    protected $fillable = [
        'institution_id',
        'bot_id',
        'conversation_id',
        'message_id',
        'rating',
        'comment',
        'user_id',
    ];

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
