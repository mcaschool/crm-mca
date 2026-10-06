<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Reclamo persistente de un mensaje externo por el asesor (idempotencia definitiva): índice
 * único (institution_id, channel, external_message_id). Ver AdvisorTurnService.
 *
 * @property int $institution_id
 * @property string $channel
 * @property string $external_message_id
 * @property int|null $conversation_id
 * @property int|null $reply_message_id
 * @property string $status processing | done
 */
class AdvisorMessageReceipt extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'channel',
        'external_message_id',
        'conversation_id',
        'reply_message_id',
        'status',
    ];
}
