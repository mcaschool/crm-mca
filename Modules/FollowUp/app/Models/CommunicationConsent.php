<?php

declare(strict_types=1);

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * @property int $institution_id
 * @property int|null $contact_id
 * @property int|null $social_conversation_id
 * @property string $subject_key
 * @property string $channel
 * @property string $purpose
 * @property string $state
 * @property string|null $source
 * @property string|null $evidence
 * @property string $policy_version
 * @property int $lock_version
 * @property int|null $recorded_by_user_id
 * @property \Carbon\CarbonInterface|null $granted_at
 * @property \Carbon\CarbonInterface|null $withdrawn_at
 */
class CommunicationConsent extends Model
{
    use BelongsToInstitution;

    public const STATES = ['unknown', 'granted', 'denied', 'withdrawn'];

    public const PURPOSES = ['requested_follow_up', 'marketing', 'operational'];

    protected $fillable = ['institution_id', 'contact_id', 'social_conversation_id', 'subject_key', 'channel', 'purpose', 'state', 'source', 'evidence', 'granted_at', 'withdrawn_at', 'recorded_by_user_id', 'policy_version', 'lock_version'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'withdrawn_at' => 'datetime', 'lock_version' => 'integer'];
    }

    /** @return HasMany<CommunicationConsentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CommunicationConsentEvent::class);
    }
}
