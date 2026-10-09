<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Institutions\Models\Bot;

/**
 * Respuesta aprobada por el equipo para una pregunta de un asesor (ver la migración
 * create_advisor_corrections_table y AdvisorCorrections). Aislada por institución (scope global).
 *
 * @property int $institution_id
 * @property int $bot_id
 * @property int|null $feedback_id
 * @property string $question
 * @property string|null $topic_line
 * @property string $answer
 * @property bool $active
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AdvisorCorrection extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'bot_id',
        'feedback_id',
        'question',
        'topic_line',
        'answer',
        'active',
        'user_id',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
