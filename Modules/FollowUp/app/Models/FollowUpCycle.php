<?php

declare(strict_types=1);

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * @property int $institution_id
 * @property int $follow_up_opportunity_id
 * @property int $sequence
 * @property string $status
 * @property string $classification
 * @property string $priority
 * @property int $score
 * @property array<int,string> $reason_codes
 * @property array<string,int|bool|string|null>|null $context_snapshot
 * @property int|null $source_first_message_id
 * @property int|null $source_last_message_id
 * @property int $previous_attempts
 * @property \Carbon\CarbonInterface $started_at
 * @property \Carbon\CarbonInterface $detected_at
 * @property \Carbon\CarbonInterface|null $ended_at
 * @property string|null $end_reason
 */
class FollowUpCycle extends Model
{
    use BelongsToInstitution;

    protected $fillable = ['institution_id', 'follow_up_opportunity_id', 'sequence', 'status', 'classification', 'priority', 'score', 'reason_codes', 'context_snapshot', 'source_first_message_id', 'source_last_message_id', 'previous_attempts', 'started_at', 'detected_at', 'ended_at', 'end_reason'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'score' => 'integer', 'previous_attempts' => 'integer', 'reason_codes' => 'array', 'context_snapshot' => 'array', 'started_at' => 'datetime', 'detected_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /** @return BelongsTo<FollowUpOpportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(FollowUpOpportunity::class, 'follow_up_opportunity_id');
    }

    /** @return HasMany<FollowUpAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(FollowUpAction::class);
    }
}
